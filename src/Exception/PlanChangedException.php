<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Exception;

/**
 * The state of this system is no longer what the person saw in the plan (an article, an address or an image changed in
 * the meantime). Nothing was written; the package has to be analyzed again.
 */
class PlanChangedException extends ContentTransferException
{
    public function __construct(string $message = 'The system has changed since the package was analyzed. Please check the package again.', string $reason = 'plan.changed')
    {
        parent::__construct($message, $reason);
    }
}
