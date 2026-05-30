<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

final class Usage
{
    public function __construct(
        public readonly int $postId,
        public readonly string $postTitle,
        public readonly string $postType,
        public readonly string $postStatus,
        public readonly string $metaKey,
        public readonly string $elementId,
        public readonly string $elementName,   // type slug, e.g. "section", "button"
        public readonly ?string $elementLabel, // user/Bricks-set label, e.g. "Submit Wrapper"
    ) {
    }
}
