<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Controller;

use Manuxi\SuluContentTransferBundle\Controller\Admin\ImportController;
use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Exception\ImportDeniedException;
use Manuxi\SuluContentTransferBundle\Exception\InvalidPackageException;
use Manuxi\SuluContentTransferBundle\Exception\PackageLimitExceededException;
use Manuxi\SuluContentTransferBundle\Exception\PlanChangedException;
use Manuxi\SuluContentTransferBundle\Format\ManifestSource;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\ImportExecutor;
use Manuxi\SuluContentTransferBundle\Import\Plan\ImportPlan;
use Manuxi\SuluContentTransferBundle\Import\PlanPresenter;
use Manuxi\SuluContentTransferBundle\Import\PlanStore;
use Manuxi\SuluContentTransferBundle\Import\Report\ArticleResult;
use Manuxi\SuluContentTransferBundle\Import\Report\ImportReport;
use Manuxi\SuluContentTransferBundle\Import\Upload\UploadValidator;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Security\ImportAccess;
use Manuxi\SuluContentTransferBundle\Tests\Support\TemporaryDirectoryTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Sulu\Component\Security\Authentication\UserInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class ImportControllerTest extends TemporaryDirectoryTestCase
{
    private const TOKEN = 'abcdef0123456789abcdef0123456789';
    private const UUID = '0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8';

    /** @var MockObject&ImportAnalyzer */
    private $analyzer;
    /** @var MockObject&ImportExecutor */
    private $executor;
    /** @var MockObject&PlanStore */
    private $planStore;
    /** @var MockObject&PlanPresenter */
    private $presenter;
    /** @var MockObject&ImportAccess */
    private $access;
    private ?ImportDecisions $executed = null;
    private ?string $executedBy = null;
    /** @var (callable(ImportDecisions): ImportDecisions)|null */
    private $enforce;

    public function testAnalyzeAnswersWithThePlanOfTheUpload(): void
    {
        $file = $this->upload('package.sulu-transfer.zip', 'zip bytes');
        $this->analyzer->expects($this->once())->method('analyze')->with($file->getPathname())->willReturn($this->plan());
        $this->presenter->method('present')->willReturn(['token' => self::TOKEN, 'articles' => []]);

        $response = $this->controller()->analyze($this->multipart($file));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['token' => self::TOKEN, 'articles' => []], $this->json($response));
    }

    public function testAnalyzeIsRefusedToUsersWhoMayNotImportAtAll(): void
    {
        $this->access->method('assertMayImport')->willThrowException(new ImportDeniedException([]));
        $this->analyzer->expects($this->never())->method('analyze');

        $response = $this->controller()->analyze($this->multipart($this->upload('package.sulu-transfer.zip', 'zip bytes')));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('import.denied', $this->json($response)['code']);
    }

    public function testAnalyzeWithoutAFileIsABadRequest(): void
    {
        $response = $this->controller()->analyze(new Request());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('import.no_file', $this->json($response)['code']);
    }

    public function testRequestLargerThanPostMaxSizeAnswers413WithTheLimit(): void
    {
        // PHP drops files and fields of such a request, so nothing but the length of the request tells
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string) (9 * 1024 * 1024)]);

        $response = $this->controller(postMaxSize: 8 * 1024 * 1024)->analyze($request);

        $this->assertSame(413, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame('import.upload_too_large', $body['code']);
        $this->assertSame(8 * 1024 * 1024, $body['limit']);
    }

    public function testRequestWithoutALimitOrWithinItIsNotTooLarge(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string) (9 * 1024 * 1024)]);

        $this->assertSame(400, $this->controller(postMaxSize: 0)->analyze($request)->getStatusCode());
        $this->assertSame(400, $this->controller(postMaxSize: 16 * 1024 * 1024)->analyze($request)->getStatusCode());
    }

    public function testFileOverUploadMaxFilesizeAnswers413(): void
    {
        $file = new UploadedFile($this->directory.'/none', 'big.sulu-transfer.zip', 'application/zip', \UPLOAD_ERR_INI_SIZE, true);

        $response = $this->controller(uploadMaxFilesize: 2 * 1024 * 1024)->analyze($this->multipart($file));

        $this->assertSame(413, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame('import.upload_too_large', $body['code']);
        $this->assertSame(2 * 1024 * 1024, $body['limit']);
    }

    public function testFileOverTheLimitOfThePackageSizeAnswers413(): void
    {
        $file = $this->upload('big.sulu-transfer.zip', str_repeat('x', 2048));
        $this->analyzer->expects($this->never())->method('analyze');

        $response = $this->controller(maxPackageSize: 1024)->analyze($this->multipart($file));

        $this->assertSame(413, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame('import.upload_too_large', $body['code']);
        $this->assertSame(1024, $body['limit']);
    }

    public function testBrokenUploadIsReported(): void
    {
        $file = new UploadedFile($this->directory.'/none', 'p.zip', 'application/zip', \UPLOAD_ERR_PARTIAL, true);

        $response = $this->controller()->analyze($this->multipart($file));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('import.upload_failed', $this->json($response)['code']);
    }

    public function testPackageErrorsAnswerWithTheirReason(): void
    {
        $file = $this->upload('p.zip', 'not a zip');
        $this->analyzer->method('analyze')->willThrowException(new InvalidPackageException('The file is not a ZIP archive.', 'package.zip'));

        $response = $this->controller()->analyze($this->multipart($file));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['error' => 'The file is not a ZIP archive.', 'code' => 'package.zip'], $this->json($response));
    }

    public function testPackageLimitsOfTheReaderAreReported(): void
    {
        $file = $this->upload('p.zip', 'zip');
        $this->analyzer->method('analyze')->willThrowException(PackageLimitExceededException::files(10));

        $response = $this->controller()->analyze($this->multipart($file));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('package.too_many_files', $this->json($response)['code']);
    }

    public function testExecuteWritesWithTheDecisionsAndTheLoggedInUser(): void
    {
        $this->enforce = static fn (ImportDecisions $decisions): ImportDecisions => new ImportDecisions(
            $decisions->actions + ['0b1c2d3e-0000-4000-8000-000000000000' => 'skip'],
            $decisions->locales,
            $decisions->keepStatus,
            $decisions->overwriteMediaMeta,
        );

        $response = $this->controller()->execute($this->post([
            'token' => self::TOKEN,
            'actions' => [self::UUID => 'update'],
            'locales' => ['de'],
            'keepStatus' => true,
            'overwriteMediaMeta' => true,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertFalse($body['hasFailures']);
        $this->assertSame(self::UUID, $body['report']['articles'][0]['sourceUuid']);
        $this->assertSame('editor', $this->executedBy);
        $this->assertNotNull($this->executed);
        $this->assertSame([self::UUID => 'update', '0b1c2d3e-0000-4000-8000-000000000000' => 'skip'], $this->executed->actions);
        $this->assertSame(['de'], $this->executed->locales);
        $this->assertTrue($this->executed->keepStatus);
        $this->assertTrue($this->executed->overwriteMediaMeta);
    }

    public function testExecuteDefaultsToAllLanguagesAndDrafts(): void
    {
        $this->controller()->execute($this->post(['token' => self::TOKEN]));

        $this->assertNotNull($this->executed);
        $this->assertNull($this->executed->locales);
        $this->assertFalse($this->executed->keepStatus);
        $this->assertFalse($this->executed->overwriteMediaMeta);
    }

    public function testExecuteReportsFailures(): void
    {
        $response = $this->controller(failedArticle: true)->execute($this->post(['token' => self::TOKEN]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->json($response)['hasFailures']);
    }

    public function testExecuteRefusesWhatTheUserMayNotDo(): void
    {
        $this->enforce = static function (): never {
            throw new ImportDeniedException([self::UUID => 'import.denied_edit']);
        };

        $response = $this->controller()->execute($this->post(['token' => self::TOKEN, 'actions' => [self::UUID => 'update']]));

        $this->assertSame(403, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame('import.denied', $body['code']);
        $this->assertSame([self::UUID => 'import.denied_edit'], $body['articles']);
        $this->assertNull($this->executed, 'nothing may be written');
    }

    public function testExecuteWithoutALoggedInUserIsRefused(): void
    {
        $response = $this->controller(user: false)->execute($this->post(['token' => self::TOKEN]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->executed);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function failureStatuses(): iterable
    {
        yield 'expired' => ['plan.expired', 410];
        yield 'missing' => ['plan.missing', 404];
        yield 'changed' => ['plan.changed', 409];
        yield 'token' => ['plan.token_invalid', 400];
        yield 'action not allowed' => ['import.action_not_allowed', 400];
        yield 'unknown article' => ['import.article_unknown', 400];
        yield 'user disabled' => ['import.user_disabled', 403];
        yield 'anything else' => ['package.integrity', 422];
    }

    /**
     * @dataProvider failureStatuses
     */
    public function testExecuteAnswersFailuresOfTheExecutorWithTheirReason(string $reason, int $status): void
    {
        $response = $this->controller(executorFailure: 'plan.changed' === $reason ? new PlanChangedException() : new ContentTransferException('Failed.', $reason))
            ->execute($this->post(['token' => self::TOKEN]));

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($reason, $this->json($response)['code']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badBodies(): iterable
    {
        yield 'no json' => ['nonsense'];
        yield 'no token' => ['{"actions": {}}'];
        yield 'token is a number' => ['{"token": 5}'];
        yield 'actions is a list of numbers' => ['{"token": "abcdef0123456789abcdef0123456789", "actions": {"a": 1}}'];
        yield 'actions is a string' => ['{"token": "abcdef0123456789abcdef0123456789", "actions": "update"}'];
        yield 'locales is a string' => ['{"token": "abcdef0123456789abcdef0123456789", "locales": "de"}'];
        yield 'locale is a number' => ['{"token": "abcdef0123456789abcdef0123456789", "locales": [1]}'];
        yield 'keepStatus is text' => ['{"token": "abcdef0123456789abcdef0123456789", "keepStatus": "yes"}'];
    }

    /**
     * @dataProvider badBodies
     */
    public function testExecuteRejectsBadRequests(string $body): void
    {
        $response = $this->controller()->execute(new Request([], [], [], [], [], [], $body));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('import.bad_request', $this->json($response)['code']);
        $this->assertNull($this->executed);
    }

    public function testDiscardThrowsAwayTheAnalysis(): void
    {
        $controller = $this->controller();
        $this->executor->expects($this->once())->method('discard')->with(self::TOKEN);

        $this->assertSame(204, $controller->discard(self::TOKEN)->getStatusCode());
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function controller(
        ?int $postMaxSize = null,
        ?int $uploadMaxFilesize = null,
        int $maxPackageSize = 256 * 1024 * 1024,
        bool $user = true,
        ?\Throwable $executorFailure = null,
        bool $failedArticle = false,
    ): ImportController {
        $this->executed = null;
        $this->executedBy = null;

        $executor = $this->createMock(ImportExecutor::class);
        $executor->method('execute')->willReturnCallback(function (string $token, ImportDecisions $decisions, string $username) use ($executorFailure, $failedArticle): ImportReport {
            if (null !== $executorFailure) {
                throw $executorFailure;
            }
            $this->executed = $decisions;
            $this->executedBy = $username;

            return new ImportReport($token, new \DateTimeImmutable(), new \DateTimeImmutable(), [
                new ArticleResult(self::UUID, 'Title', 'update', $failedArticle ? ArticleResult::FAILED : ArticleResult::UPDATED, self::UUID, ['de']),
            ], [], [], []);
        });
        $this->executor = $executor;

        $tokens = new TokenStorage();
        if ($user) {
            $account = $this->createMock(UserInterface::class);
            $account->method('getUserIdentifier')->willReturn('editor');
            $account->method('getRoles')->willReturn([]);
            $tokens->setToken(new UsernamePasswordToken($account, 'admin', []));
        }

        return new ImportController(
            $this->analyzer,
            $this->executor,
            $this->planStore,
            $this->presenter,
            $this->access,
            new UploadValidator(new PackageLimits($maxPackageSize), $postMaxSize, $uploadMaxFilesize),
            $tokens,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = $this->createMock(ImportAnalyzer::class);
        $this->executor = $this->createMock(ImportExecutor::class);
        $this->planStore = $this->createMock(PlanStore::class);
        $this->presenter = $this->createMock(PlanPresenter::class);
        $this->access = $this->createMock(ImportAccess::class);
        $this->planStore->method('load')->willReturn($this->plan());
        $this->access->method('enforce')->willReturnCallback(fn (ImportPlan $plan, ImportDecisions $decisions): ImportDecisions => null !== $this->enforce ? ($this->enforce)($decisions) : $decisions);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = $this->directory.'/'.uniqid('upload', true);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    private function multipart(UploadedFile $file): Request
    {
        return new Request([], [], [], [], ['file' => $file], ['REQUEST_METHOD' => 'POST']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        return new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($data);

        return $data;
    }

    private function plan(): ImportPlan
    {
        return new ImportPlan(
            self::TOKEN,
            new \DateTimeImmutable('2026-10-19 10:00'),
            new \DateTimeImmutable('2026-10-20 10:00'),
            new ManifestSource('aaaabbbbccccdddd', 'source.example', '3.0.9', '1.0.0'),
            new \DateTimeImmutable('2026-10-18 10:00'),
            [],
            false,
            'target',
            'target.example',
            [],
            [],
            [],
            [],
            [],
            [],
        );
    }
}
