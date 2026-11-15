<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * A provider broke the contract: a malformed result, outcome or definition.
 *
 * The message names the rule that failed. It never carries provider text.
 */
final class ContractViolation extends \RuntimeException
{
}
