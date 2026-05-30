<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksFormManager;

final class Form
{
    public function __construct(
        public readonly int $postId,
        public readonly string $postTitle,
        public readonly string $postType,
        public readonly string $postStatus,
        public readonly string $metaKey,
        public readonly string $elementId,
        public readonly string $formType,        // 'bricks' | 'brf-pro'
        public readonly ?string $fromName,
        public readonly ?string $fromEmail,
        public readonly ?string $replyToEmail,
        public readonly ?string $emailTo,
        public readonly ?string $emailCc,
        public readonly ?string $emailSubject,
        public readonly ?string $successMessage,
        public readonly ?string $emailErrorMessage,
    ) {
    }

    public function formTypeLabel(): string
    {
        return $this->formType === 'brf-pro'
            ? __('BricksForge Pro', 'ab-bricks-tools')
            : __('Bricks Core', 'ab-bricks-tools');
    }
}
