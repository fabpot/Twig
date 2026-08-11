<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extra\ContextualEscaping\Analysis;

/**
 * @internal
 *
 * @experimental
 */
final class CallableAnalysis
{
    public function __construct(
        private ContentTypeSet $contentTypes,
        private ValueContract $valueContract,
    ) {
    }

    public function getContentTypes(): ContentTypeSet
    {
        return $this->contentTypes;
    }

    public function getValueContract(): ValueContract
    {
        return $this->valueContract;
    }
}
