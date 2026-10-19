<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Controller\Admin;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Exception\ImportDeniedException;
use Manuxi\SuluContentTransferBundle\Exception\UploadTooLargeException;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\ImportExecutor;
use Manuxi\SuluContentTransferBundle\Import\PlanPresenter;
use Manuxi\SuluContentTransferBundle\Import\PlanStore;
use Manuxi\SuluContentTransferBundle\Package\PackageLimits;
use Manuxi\SuluContentTransferBundle\Security\ImportAccess;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * POST   /admin/api/content-transfer/import/analyze   multipart, field "file": the package   → the plan and its token
 * POST   /admin/api/content-transfer/import/execute   body: {"token": ..., "actions": {uuid: action}, "locales": [...]|null,
 *                                                     "keepStatus": false, "overwriteMediaMeta": false}   → the report
 * DELETE /admin/api/content-transfer/import/{token}   throws away an analysis that is not carried out
 *
 * The analysis is the one of the console (--dry-run) and the execution the one of the console command; the controller
 * only adds the permissions of the logged-in user, who is also the creator and changer of everything that is written.
 * Errors answer as {"error": text, "code": reason, ...} with the reasons of the ContentTransferException, so the admin
 * can show them in the language of the user.
 *
 * Own prefix on purpose: a path below /admin/api/{resourceKey}/ would collide with the routes of the resources.
 */
class ImportController
{
    private const MAX_ARTICLES = 500;

    public function __construct(
        private readonly ImportAnalyzer $analyzer,
        private readonly ImportExecutor $executor,
        private readonly PlanStore $planStore,
        private readonly PlanPresenter $presenter,
        private readonly ImportAccess $access,
        private readonly PackageLimits $limits,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ?int $postMaxSize = null,
        private readonly ?int $uploadMaxFilesize = null,
    ) {
    }

    #[Route('/content-transfer/import/analyze', name: 'import_analyze', methods: ['POST'])]
    public function analyze(Request $request): JsonResponse
    {
        try {
            $file = $this->uploadedFile($request);
            $plan = $this->analyzer->analyze($file->getPathname());

            return new JsonResponse($this->presenter->present($plan));
        } catch (ContentTransferException $exception) {
            return $this->error($exception);
        }
    }

    #[Route('/content-transfer/import/execute', name: 'import_execute', methods: ['POST'])]
    public function execute(Request $request): JsonResponse
    {
        try {
            [$token, $decisions] = $this->parse($request);
            $username = $this->tokenStorage->getToken()?->getUser()?->getUserIdentifier();
            if (!\is_string($username)) {
                throw new ContentTransferException('There is no logged-in user.', 'import.user_unknown');
            }

            $decisions = $this->access->enforce($this->planStore->load($token), $decisions);

            // A long import must not stop halfway because the person closes the tab or a time limit of PHP runs out.
            ignore_user_abort(true);
            if (\function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $report = $this->executor->execute($token, $decisions, $username);

            return new JsonResponse(['report' => $report->toArray(), 'hasFailures' => $report->hasFailures()]);
        } catch (ContentTransferException $exception) {
            return $this->error($exception);
        }
    }

    #[Route('/content-transfer/import/{token}', name: 'import_discard', methods: ['DELETE'], requirements: ['token' => '[0-9a-f]{32}'])]
    public function discard(string $token): Response
    {
        $this->executor->discard($token);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws UploadTooLargeException when the server did not accept the upload because of its size
     * @throws ContentTransferException
     */
    private function uploadedFile(Request $request): UploadedFile
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            // PHP drops the whole request (files and fields) when it is larger than post_max_size
            $postMax = $this->postMaxSize ?? self::iniBytes('post_max_size');
            $length = (int) $request->headers->get('Content-Length', '0');
            if ($postMax > 0 && $length > $postMax) {
                throw new UploadTooLargeException($postMax);
            }

            throw new ContentTransferException('No package was uploaded (form field "file").', 'import.no_file');
        }

        if (!$file->isValid()) {
            if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                $limit = $this->uploadMaxFilesize ?? self::iniBytes('upload_max_filesize');

                throw new UploadTooLargeException($limit > 0 ? $limit : null);
            }

            throw new ContentTransferException('The upload failed: '.$file->getErrorMessage(), 'import.upload_failed');
        }

        if ($file->getSize() > $this->limits->maxPackageSize) {
            throw new UploadTooLargeException($this->limits->maxPackageSize);
        }

        return $file;
    }

    /**
     * @return array{string, ImportDecisions}
     */
    private function parse(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new ContentTransferException('The request must be a JSON object.', 'import.bad_request');
        }

        $token = $data['token'] ?? null;
        if (!\is_string($token)) {
            throw new ContentTransferException('"token" must be the token of an analysis.', 'import.bad_request');
        }

        $actions = [];
        $rawActions = $data['actions'] ?? [];
        if (!\is_array($rawActions) || \count($rawActions) > self::MAX_ARTICLES) {
            throw new ContentTransferException('"actions" must be an object of article UUIDs and actions.', 'import.bad_request');
        }
        foreach ($rawActions as $uuid => $action) {
            if (!\is_string($action)) {
                throw new ContentTransferException('"actions" must be an object of article UUIDs and actions.', 'import.bad_request');
            }
            $actions[(string) $uuid] = $action;
        }

        $locales = null;
        if (null !== ($data['locales'] ?? null)) {
            if (!\is_array($data['locales'])) {
                throw new ContentTransferException('"locales" must be a list of languages or null.', 'import.bad_request');
            }
            $locales = [];
            foreach ($data['locales'] as $locale) {
                if (!\is_string($locale)) {
                    throw new ContentTransferException('"locales" must be a list of languages or null.', 'import.bad_request');
                }
                $locales[] = $locale;
            }
        }

        foreach (['keepStatus', 'overwriteMediaMeta'] as $flag) {
            if (isset($data[$flag]) && !\is_bool($data[$flag])) {
                throw new ContentTransferException(\sprintf('"%s" must be true or false.', $flag), 'import.bad_request');
            }
        }

        return [$token, new ImportDecisions($actions, $locales, true === ($data['keepStatus'] ?? false), true === ($data['overwriteMediaMeta'] ?? false))];
    }

    private function error(ContentTransferException $exception): JsonResponse
    {
        $status = match ($exception->getReason()) {
            ImportDeniedException::REASON, 'import.user_unknown', 'import.user_disabled' => Response::HTTP_FORBIDDEN,
            'plan.missing' => Response::HTTP_NOT_FOUND,
            'plan.expired' => Response::HTTP_GONE,
            'plan.changed' => Response::HTTP_CONFLICT,
            UploadTooLargeException::REASON => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            'import.bad_request', 'import.no_file', 'import.action_unknown', 'import.action_not_allowed', 'import.article_unknown', 'plan.token_invalid' => Response::HTTP_BAD_REQUEST,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };

        $body = ['error' => $exception->getMessage(), 'code' => $exception->getReason()];
        if ($exception instanceof ImportDeniedException) {
            $body['articles'] = $exception->reasons;
        }
        if ($exception instanceof UploadTooLargeException) {
            $body['limit'] = $exception->limit;
        }

        return new JsonResponse($body, $status);
    }

    /**
     * A PHP size setting ("2M", "8G", "0") in bytes; 0 means no limit.
     */
    private static function iniBytes(string $name): int
    {
        $value = trim((string) \ini_get($name));
        if ('' === $value || !preg_match('/^(-?\d+)\s*([kmg]?)b?$/i', $value, $matches)) {
            return 0;
        }

        $bytes = (int) $matches[1] * match (strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };

        return max(0, $bytes);
    }
}
