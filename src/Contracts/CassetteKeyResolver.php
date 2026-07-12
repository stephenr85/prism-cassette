<?php

namespace Rushing\PrismCassette\Contracts;

use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

interface CassetteKeyResolver
{
    public function forEmbeddings(EmbeddingsRequest $request): string;

    public function forText(TextRequest $request): string;

    public function forStructured(StructuredRequest $request): string;
}
