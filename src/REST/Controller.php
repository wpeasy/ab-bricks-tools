<?php
declare(strict_types=1);

namespace AB\BricksTools\REST;

use AB\BricksTools\Modules\Registrar;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Controller
{
    public const REST_NAMESPACE = 'abbtl/v1';

    public function __construct(private Registrar $registrar)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/modules/(?P<slug>[a-z0-9-]+)/enabled', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'setEnabled'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'enabled' => [
                    'required' => true,
                    'type'     => 'boolean',
                ],
            ],
        ]);
    }

    public function setEnabled(WP_REST_Request $request): WP_REST_Response
    {
        $slug    = (string) $request->get_param('slug');
        $enabled = (bool) $request->get_param('enabled');

        if (!$this->registrar->get($slug)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => __('Unknown module.', 'ab-bricks-tools'),
            ], 404);
        }

        $this->registrar->setEnabled($slug, $enabled);

        return new WP_REST_Response([
            'success' => true,
            'slug'    => $slug,
            'enabled' => $enabled,
        ]);
    }
}
