<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Experimental\ContextualEscaping;

/**
 * @internal
 *
 * @experimental
 */
final class ContextualEscapingCallableAnalysis
{
    /**
     * @param non-empty-list<string> $provenance
     */
    public function __construct(
        private ContentTypeSet $contentTypes,
        private array $provenance,
    ) {
    }

    public function getContentTypes(): ContentTypeSet
    {
        return $this->contentTypes;
    }

    /**
     * @return non-empty-list<string>
     */
    public function getProvenance(): array
    {
        return $this->provenance;
    }
}
