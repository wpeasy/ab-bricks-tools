<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksFormManager;

use AB\BricksTools\Admin\Layout;
use AB\BricksTools\Modules\HasAdminPage;
use AB\BricksTools\Modules\ModuleInterface;
use AB\BricksTools\System\WpCli;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Module implements ModuleInterface, HasAdminPage
{
    public const REST_ROUTE_SCAN = '/bricks-form-manager/forms';
    public const REST_ROUTE_SAVE = '/bricks-form-manager/form';

    private const SAVE_TEXT_FIELDS = [
        'fromName', 'fromEmail', 'replyToEmail', 'emailTo', 'emailCc', 'emailSubject',
    ];

    private const SAVE_HTML_FIELDS = [
        'successMessage', 'emailErrorMessage',
    ];

    public function getSlug(): string
    {
        return 'bricks-form-manager';
    }

    public function getName(): string
    {
        return __('Bricks Form Manager', 'ab-bricks-tools');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return __(
            'Manage From, To, CC, Subject and Success Message for Bricks Core Forms and BricksForge Pro Forms.',
            'ab-bricks-tools'
        );
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('abbtl/v1', self::REST_ROUTE_SCAN, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'restGetForms'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_SAVE, [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'restSaveField'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'postId'    => ['required' => true, 'type' => 'integer'],
                'metaKey'   => ['required' => true, 'type' => 'string'],
                'elementId' => ['required' => true, 'type' => 'string'],
                'field'     => ['required' => true, 'type' => 'string'],
                'value'     => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    public function restGetForms(): WP_REST_Response
    {
        $finder = new FormFinder();
        $forms  = $finder->findAll();

        $data = array_map(static function (Form $f): array {
            return [
                'postId'            => $f->postId,
                'postTitle'         => $f->postTitle,
                'postType'          => $f->postType,
                'postStatus'        => $f->postStatus,
                'metaKey'           => $f->metaKey,
                'elementId'         => $f->elementId,
                'formType'          => $f->formType,
                'formTypeLabel'     => $f->formTypeLabel(),
                'fromName'          => $f->fromName,
                'fromEmail'         => $f->fromEmail,
                'replyToEmail'      => $f->replyToEmail,
                'emailTo'           => $f->emailTo,
                'emailCc'           => $f->emailCc,
                'emailSubject'      => $f->emailSubject,
                'successMessage'    => $f->successMessage,
                'emailErrorMessage' => $f->emailErrorMessage,
                'editUrl'           => self::buildBricksBuilderUrl($f->postId),
            ];
        }, $forms);

        return new WP_REST_Response([
            'forms'  => $data,
            'engine' => $finder->lastEngine,
        ]);
    }

    public function restSaveField(WP_REST_Request $request): WP_REST_Response
    {
        $postId    = (int) $request->get_param('postId');
        $metaKey   = (string) $request->get_param('metaKey');
        $elementId = (string) $request->get_param('elementId');
        $field     = (string) $request->get_param('field');
        $rawValue  = (string) $request->get_param('value');

        if (!in_array($field, self::SAVE_TEXT_FIELDS, true)
            && !in_array($field, self::SAVE_HTML_FIELDS, true)
        ) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid field'], 400);
        }

        if ($postId <= 0 || !get_post($postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid post'], 400);
        }

        if (!current_user_can('edit_post', $postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Forbidden'], 403);
        }

        if (!preg_match('/^_bricks_page_(content|header|footer)(?:_\d+)?$/', $metaKey)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid meta key'], 400);
        }

        $cleaned = in_array($field, self::SAVE_HTML_FIELDS, true)
            ? wp_kses_post($rawValue)
            : sanitize_text_field($rawValue);

        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $postId,
            $metaKey
        ));

        if ($raw === null) {
            return new WP_REST_Response(['success' => false, 'error' => 'Form storage not found'], 404);
        }

        $wasJsonString = self::looksLikeJsonContainer($raw);

        $elements = maybe_unserialize($raw);
        if (is_string($elements)) {
            $decoded = json_decode($elements, true);
            if (is_array($decoded)) {
                $elements = $decoded;
            }
        }
        if (!is_array($elements)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Unable to decode form storage'], 500);
        }

        $found = false;
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['id'] ?? null) !== $elementId) {
                continue;
            }
            $element['settings'] = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            if ($cleaned === '') {
                unset($element['settings'][$field]);
            } else {
                $element['settings'][$field] = $cleaned;
            }
            $found = true;
            break;
        }
        unset($element);

        if (!$found) {
            return new WP_REST_Response(['success' => false, 'error' => 'Form element not found in post'], 404);
        }

        if ($wasJsonString) {
            $encoded = wp_json_encode($elements, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return new WP_REST_Response(['success' => false, 'error' => 'JSON encode failed'], 500);
            }
            // update_post_meta wp_unslashes its input — pre-slash so the stored value is exact.
            update_post_meta($postId, $metaKey, wp_slash($encoded));
        } else {
            update_post_meta($postId, $metaKey, wp_slash($elements));
        }

        return new WP_REST_Response([
            'success' => true,
            'field'   => $field,
            'value'   => $cleaned,
        ]);
    }

    /**
     * Build the front-end URL that opens this post in the Bricks Builder:
     * `<post-permalink>?bricks=run`. Falls back to the WP admin edit screen if
     * the post has no public permalink (e.g. some private CPTs).
     */
    private static function buildBricksBuilderUrl(int $postId): string
    {
        $permalink = get_permalink($postId);
        if (is_string($permalink) && $permalink !== '') {
            return add_query_arg('bricks', 'run', $permalink);
        }
        return (string) (get_edit_post_link($postId, 'raw') ?: '');
    }

    /**
     * Bricks stores element trees as either a PHP-serialized array or a raw
     * JSON-encoded string. Detect the latter so we can re-encode in the same
     * format on save.
     */
    private static function looksLikeJsonContainer(?string $raw): bool
    {
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $first = ltrim($raw)[0] ?? '';
        return $first === '{' || $first === '[';
    }

    public function renderAdminPage(): void
    {
        $wpcli = WpCli::status();
        Layout::open();
        ?>
        <div class="abbtl-bfm">
            <h1>
                <?php echo esc_html($this->getName()); ?>
                <span style="font-size:13px;color:#646970;font-weight:normal;margin-left:8px;">
                    v<?php echo esc_html($this->getVersion()); ?>
                </span>
            </h1>
            <p class="description"><?php echo esc_html($this->getDescription()); ?></p>

            <?php $this->renderWpCliNotice($wpcli); ?>

            <div x-data="abbtlBfmApp()" style="margin-top:24px;">
                <p>
                    <button
                        type="button"
                        class="button button-primary"
                        @click="scan()"
                        :disabled="scanning"
                    >
                        <span x-show="!scanning"><?php esc_html_e('Scan for Forms', 'ab-bricks-tools'); ?></span>
                        <span x-show="scanning" x-cloak><?php esc_html_e('Scanning…', 'ab-bricks-tools'); ?></span>
                    </button>
                    <span
                        x-show="scanned && !error"
                        x-cloak
                        style="margin-left:12px;color:#50575e;"
                    >
                        <span x-text="forms.length + ' <?php echo esc_attr__('form(s) found', 'ab-bricks-tools'); ?>'"></span>
                        <small style="margin-left:8px;color:#646970;">
                            <?php esc_html_e('engine:', 'ab-bricks-tools'); ?>
                            <code x-text="engine || 'unknown'"></code>
                        </small>
                    </span>
                </p>

                <div x-show="error" x-cloak class="notice notice-error inline">
                    <p x-text="error"></p>
                </div>

                <div x-show="scanned && forms.length > 0" x-cloak>
                    <div class="abbtl-bfm__toolbar">
                        <label class="abbtl-bfm__toolbar-field">
                            <span><?php esc_html_e('Type:', 'ab-bricks-tools'); ?></span>
                            <select x-model="typeFilter">
                                <option value="all"><?php esc_html_e('All Types', 'ab-bricks-tools'); ?></option>
                                <option value="bricks"><?php esc_html_e('Bricks Core', 'ab-bricks-tools'); ?></option>
                                <option value="brf-pro"><?php esc_html_e('BricksForge Pro', 'ab-bricks-tools'); ?></option>
                            </select>
                        </label>
                        <label class="abbtl-bfm__toolbar-field abbtl-bfm__toolbar-field--grow">
                            <span><?php esc_html_e('Search email:', 'ab-bricks-tools'); ?></span>
                            <input
                                type="search"
                                x-model.debounce.200ms="emailSearch"
                                placeholder="<?php echo esc_attr__('To, From, or Reply To…', 'ab-bricks-tools'); ?>"
                            />
                        </label>
                        <span class="abbtl-bfm__toolbar-count" x-text="filteredForms.length + ' / ' + forms.length"></span>
                    </div>

                    <div class="abbtl-bfm__table-wrap">
                        <table class="wp-list-table widefat striped abbtl-bfm__table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Form', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('Type', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('From Name', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('From Email', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('Reply To', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('To', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('CC', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('Subject', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('Success Message', 'ab-bricks-tools'); ?></th>
                                    <th scope="col"><?php esc_html_e('Error Message', 'ab-bricks-tools'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="form in filteredForms" :key="formKey(form)">
                                    <tr>
                                        <td>
                                            <strong>
                                                <a :href="form.editUrl" target="_blank" rel="noopener noreferrer" x-text="form.postTitle"></a>
                                            </strong>
                                            <br>
                                            <code style="font-size:11px;" x-text="form.elementId"></code>
                                            <span class="abbtl-bfm__status" x-show="form.postStatus !== 'publish'" x-cloak x-text="'(' + form.postStatus + ')'"></span>
                                        </td>
                                        <td>
                                            <span class="abbtl-bfm__badge" :class="'abbtl-bfm__badge--' + form.formType" x-text="form.formTypeLabel"></span>
                                        </td>
                                        <?php echo $this->renderEditableCell('fromName'); ?>
                                        <?php echo $this->renderEditableCell('fromEmail'); ?>
                                        <?php echo $this->renderEditableCell('replyToEmail'); ?>
                                        <?php echo $this->renderEditableCell('emailTo'); ?>
                                        <?php echo $this->renderEditableCell('emailCc'); ?>
                                        <?php echo $this->renderEditableCell('emailSubject'); ?>
                                        <?php echo $this->renderEditableCell('successMessage', true); ?>
                                        <?php echo $this->renderEditableCell('emailErrorMessage', true); ?>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <p x-show="filteredForms.length === 0" x-cloak style="margin-top:12px;color:#646970;">
                        <em><?php esc_html_e('No forms match the current filters.', 'ab-bricks-tools'); ?></em>
                    </p>
                </div>

                <p x-show="scanned && forms.length === 0 && !error" x-cloak>
                    <em><?php esc_html_e('No forms found in any Bricks page, header, footer, or template.', 'ab-bricks-tools'); ?></em>
                </p>
            </div>

            <script>
                function abbtlBfmApp() {
                    return {
                        forms: [],
                        engine: '',
                        scanning: false,
                        scanned: false,
                        error: '',
                        typeFilter: 'all',
                        emailSearch: '',
                        editing: null,

                        get filteredForms() {
                            const needle = (this.emailSearch || '').trim().toLowerCase();
                            return this.forms.filter(f => {
                                if (this.typeFilter !== 'all' && f.formType !== this.typeFilter) return false;
                                if (needle) {
                                    const hay = [f.emailTo, f.fromEmail, f.replyToEmail]
                                        .filter(Boolean).join(' ').toLowerCase();
                                    if (!hay.includes(needle)) return false;
                                }
                                return true;
                            });
                        },

                        formKey(form) {
                            return form.postId + '|' + form.metaKey + '|' + form.elementId;
                        },

                        isEditing(form, field) {
                            return this.editing
                                && this.editing.key === this.formKey(form)
                                && this.editing.field === field;
                        },

                        startEdit(form, field) {
                            if (this.editing) return;
                            this.editing = {
                                key: this.formKey(form),
                                form: form,
                                field: field,
                                value: form[field] == null ? '' : String(form[field]),
                                saving: false,
                                error: '',
                            };
                        },

                        cancelEdit() {
                            this.editing = null;
                        },

                        async commitEdit() {
                            if (!this.editing) return;
                            const ctx = this.editing;
                            const original = ctx.form[ctx.field] == null ? '' : String(ctx.form[ctx.field]);
                            if (ctx.value === original) {
                                this.editing = null;
                                return;
                            }
                            ctx.saving = true;
                            try {
                                const response = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_SAVE); ?>',
                                    {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-WP-Nonce': ABBTL.nonce,
                                        },
                                        body: JSON.stringify({
                                            postId: ctx.form.postId,
                                            metaKey: ctx.form.metaKey,
                                            elementId: ctx.form.elementId,
                                            field: ctx.field,
                                            value: ctx.value,
                                        }),
                                    }
                                );
                                const data = await response.json();
                                if (!response.ok || !data.success) {
                                    throw new Error(data.error || 'Save failed');
                                }
                                ctx.form[ctx.field] = data.value ?? ctx.value;
                                this.editing = null;
                            } catch (e) {
                                console.error('[ABBTL] Save failed:', e);
                                ctx.error = e.message || 'Unknown error';
                                ctx.saving = false;
                            }
                        },

                        async scan() {
                            this.scanning = true;
                            this.error = '';
                            try {
                                const response = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_SCAN); ?>',
                                    {
                                        method: 'GET',
                                        headers: { 'X-WP-Nonce': ABBTL.nonce },
                                    }
                                );
                                const data = await response.json();
                                if (!response.ok) {
                                    throw new Error(data.message || 'Request failed');
                                }
                                this.forms = Array.isArray(data.forms) ? data.forms : [];
                                this.engine = data.engine || '';
                                this.scanned = true;
                            } catch (e) {
                                console.error('[ABBTL] Scan failed:', e);
                                this.error = e.message || 'Unknown error';
                            } finally {
                                this.scanning = false;
                            }
                        },
                    };
                }
            </script>
        </div>
        <?php
        Layout::close();
    }

    /**
     * Render an editable table cell. Display mode = span of current value.
     * Edit mode (after dblclick) = input or textarea bound to `editing.value`.
     */
    private function renderEditableCell(string $fieldKey, bool $multiline = false): string
    {
        $fieldAttr = esc_attr($fieldKey);
        $fieldJs   = esc_js($fieldKey);

        $inputMarkup = $multiline
            ? sprintf(
                '<textarea class="abbtl-bfm__edit-input" rows="3" x-init="$el.focus()" x-model="editing.value" @blur="commitEdit()" @keydown.escape.prevent="cancelEdit()"></textarea>'
            )
            : '<input class="abbtl-bfm__edit-input" type="text" x-init="$el.focus(); $el.select();" x-model="editing.value" @blur="commitEdit()" @keydown.enter.prevent="commitEdit()" @keydown.escape.prevent="cancelEdit()" />';

        $tooltip = esc_attr__('Double-click to edit', 'ab-bricks-tools');

        ob_start();
        ?>
        <td class="abbtl-bfm__cell" @dblclick="startEdit(form, '<?php echo $fieldAttr; ?>')">
            <span
                class="abbtl-bfm__cell-value"
                x-show="!isEditing(form, '<?php echo $fieldAttr; ?>')"
                x-text="form.<?php echo $fieldJs; ?> || '—'"
                title="<?php echo $tooltip; ?>"
            ></span>
            <template x-if="isEditing(form, '<?php echo $fieldAttr; ?>')">
                <span class="abbtl-bfm__cell-edit" :class="{ 'is-saving': editing.saving, 'is-error': editing.error }">
                    <?php echo $inputMarkup; ?>
                    <small class="abbtl-bfm__cell-status" x-show="editing.saving" x-cloak><?php esc_html_e('Saving…', 'ab-bricks-tools'); ?></small>
                    <small class="abbtl-bfm__cell-status abbtl-bfm__cell-status--error" x-show="editing.error" x-cloak x-text="editing.error"></small>
                </span>
            </template>
        </td>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array{available: bool, version: ?string, reason: ?string} $wpcli
     */
    private function renderWpCliNotice(array $wpcli): void
    {
        if ($wpcli['available']) {
            $versionSuffix = !empty($wpcli['version']) ? ' (' . $wpcli['version'] . ')' : '';
            ?>
            <div class="notice notice-success inline" style="margin-top:16px;">
                <p>
                    <strong><?php esc_html_e('WP-CLI access confirmed', 'ab-bricks-tools'); ?><?php echo esc_html($versionSuffix); ?></strong>
                    — <?php esc_html_e('where possible this will be used (Fastest).', 'ab-bricks-tools'); ?>
                </p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="notice notice-warning inline" style="margin-top:16px;">
            <p>
                <strong><?php esc_html_e('WP-CLI is not available', 'ab-bricks-tools'); ?></strong>
                — <?php esc_html_e('all operations will be performed with PHP (Slower).', 'ab-bricks-tools'); ?>
                <?php if (!empty($wpcli['reason'])) : ?>
                    <br>
                    <small style="color:#646970;"><?php echo esc_html($wpcli['reason']); ?></small>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
