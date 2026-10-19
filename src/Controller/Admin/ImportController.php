<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Controller\Admin;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Import\Execute\ImportDecisions;
use Manuxi\SuluContentTransferBundle\Import\ImportAnalyzer;
use Manuxi\SuluContentTransferBundle\Import\ImportExecutor;
use Manuxi\SuluContentTransferBundle\Import\PlanPresenter;
use Manuxi\SuluContentTransferBundle\Import\PlanStore;
use Manuxi\SuluContentTransferBundle\Import\Upload\UploadValidator;
use Manuxi\SuluContentTransferBundle\Security\ImportAccess;
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
        private readonly UploadValidator $uploads,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/content-transfer/import/analyze', name: 'import_analyze', methods: ['POST'])]
    public function analyze(Request $request): JsonResponse
    {
        try {
            $file = $this->uploads->validate($request);
            $plan = $this->analyzer->analyze($file->getPathname());

            return new JsonResponse($this->presenter->present($plan));
        } catch (ContentTransferException $exception) {
            return ImportErrorResponse::for($exception);
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
            return ImportErrorResponse::for($exception);
        }
    }

    #[Route('/content-transfer/import/{token}', name: 'import_discard', methods: ['DELETE'], requirements: ['token' => '[0-9a-f]{32}'])]
    public function discard(string $token): Response
    {
        $this->executor->discard($token);

        return new Response('', Response::HTTP_NO_CONTENT);
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
}
