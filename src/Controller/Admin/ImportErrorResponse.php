<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Controller\Admin;

use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Manuxi\SuluContentTransferBundle\Exception\ImportDeniedException;
use Manuxi\SuluContentTransferBundle\Exception\UploadTooLargeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answer of the import endpoints to an error: {"error": text, "code": reason, ...} with a status that fits the reason.
 */
final class ImportErrorResponse
{
    private function __construct()
    {
    }

    public static function for(ContentTransferException $exception): JsonResponse
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
}
