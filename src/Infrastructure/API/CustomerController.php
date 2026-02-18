<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use LimpVix\Infrastructure\Persistence\Customer\WpCustomerRepository;
use LimpVix\Application\UseCases\Customer\GetCustomerProfile;
use LimpVix\Application\UseCases\Customer\ListCustomers;
use LimpVix\Application\UseCases\Customer\UpdateCustomerProfile;
use LimpVix\Application\UseCases\Customer\GetCustomerContracts;
use LimpVix\Application\UseCases\Customer\GetCustomerBriefings;

defined("ABSPATH") || exit;

/**
 * Customer REST API Controller
 * 
 * Endpoints:
 * - GET /customers - Listar clientes (Admin only)
 * - GET /customers/me - Perfil do cliente autenticado
 * - GET /customers/{id} - Detalhes de um cliente
 * - PUT /customers/{id} - Atualizar cliente
 * - GET /customers/{id}/contracts - Contratos do cliente
 * - GET /customers/{id}/briefings - Briefings do cliente
 */
final class CustomerController extends WP_REST_Controller
{
    private WpCustomerRepository $repository;
    private GetCustomerProfile $getProfile;
    private ListCustomers $listCustomers;
    private UpdateCustomerProfile $updateProfile;
    private GetCustomerContracts $getContracts;
    private GetCustomerBriefings $getBriefings;

    public function __construct()
    {
        $this->namespace = "limpvix/v1";
        $this->rest_base = "customers";

        $this->repository = new WpCustomerRepository();
        $this->getProfile = new GetCustomerProfile($this->repository);
        $this->listCustomers = new ListCustomers($this->repository);
        $this->updateProfile = new UpdateCustomerProfile($this->repository);
        $this->getContracts = new GetCustomerContracts($this->repository);
        $this->getBriefings = new GetCustomerBriefings($this->repository);
    }

    public function register_routes(): void
    {
        // GET /customers - Listar clientes (Admin only)
        register_rest_route($this->namespace, "/" . $this->rest_base, [
            [
                "methods" => "GET",
                "callback" => [$this, "get_items"],
                "permission_callback" => [$this, "check_admin_permission"],
                "args" => [
                    "search" => ["type" => "string", "default" => ""],
                    "status" => ["type" => "string", "enum" => ["all", "active", "inactive"], "default" => "all"],
                    "min_spent" => ["type" => "number", "default" => 0],
                    "per_page" => ["type" => "integer", "default" => 20, "minimum" => 1, "maximum" => 100],
                    "page" => ["type" => "integer", "default" => 1, "minimum" => 1],
                ],
            ],
        ]);

        // GET /customers/me - Perfil do cliente autenticado
        register_rest_route($this->namespace, "/" . $this->rest_base . "/me", [
            [
                "methods" => "GET",
                "callback" => [$this, "get_current_customer"],
                "permission_callback" => [$this, "check_authenticated"],
            ],
        ]);

        // GET /customers/{id} - Detalhes de um cliente
        register_rest_route($this->namespace, "/" . $this->rest_base . "/(?P<id>\\d+)", [
            [
                "methods" => "GET",
                "callback" => [$this, "get_item"],
                "permission_callback" => [$this, "check_customer_or_admin_permission"],
                "args" => ["id" => ["required" => true, "type" => "integer"]],
            ],
            [
                "methods" => "PUT",
                "callback" => [$this, "update_item"],
                "permission_callback" => [$this, "check_customer_or_admin_permission"],
                "args" => [
                    "id" => ["required" => true, "type" => "integer"],
                    "name" => ["type" => "string"],
                    "phone" => ["type" => "string"],
                    "email" => ["type" => "string"],
                    "address" => ["type" => "object"],
                ],
            ],
        ]);

        // GET /customers/{id}/contracts
        register_rest_route($this->namespace, "/" . $this->rest_base . "/(?P<id>\\d+)/contracts", [
            [
                "methods" => "GET",
                "callback" => [$this, "get_customer_contracts"],
                "permission_callback" => [$this, "check_customer_or_admin_permission"],
                "args" => ["id" => ["required" => true, "type" => "integer"]],
            ],
        ]);

        // GET /customers/{id}/briefings
        register_rest_route($this->namespace, "/" . $this->rest_base . "/(?P<id>\\d+)/briefings", [
            [
                "methods" => "GET",
                "callback" => [$this, "get_customer_briefings"],
                "permission_callback" => [$this, "check_customer_or_admin_permission"],
                "args" => ["id" => ["required" => true, "type" => "integer"]],
            ],
        ]);
    }

    // Métodos do controller

    public function get_items($request)
    {
        $filters = [];

        if ($request->get_param("search")) {
            $filters["search"] = $request->get_param("search");
        }

        $status = $request->get_param("status");
        if ($status && $status !== "all") {
            $filters["status"] = $status;
        }

        if ($request->get_param("min_spent") > 0) {
            $filters["min_spent"] = $request->get_param("min_spent");
        }

        try {
            $result = $this->listCustomers->execute(
                $filters,
                $request->get_param("per_page"),
                $request->get_param("page")
            );

            return new WP_REST_Response($result, 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                "success" => false,
                "error" => $e->getMessage(),
            ], 500);
        }
    }

    public function get_current_customer(WP_REST_Request $request): WP_REST_Response
    {
        $userId = $request->get_param("_user_id") ?: \get_current_user_id();

        if (!$userId) {
            return new WP_REST_Response([
                "success" => false,
                "error" => "Not authenticated",
            ], 401);
        }

        try {
            $data = $this->getProfile->execute($userId);

            return new WP_REST_Response([
                "success" => true,
                "data" => $data,
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                "success" => false,
                "error" => $e->getMessage(),
            ], 404);
        }
    }

    public function get_item($request)
    {
        $customerId = (int) $request->get_param("id");

        try {
            $data = $this->getProfile->execute($customerId);

            return new WP_REST_Response([
                "success" => true,
                "data" => $data,
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                "success" => false,
                "error" => $e->getMessage(),
            ], 404);
        }
    }

    public function update_item($request)
    {
        $customerId = (int) $request->get_param("id");
        $data = [];

        if ($request->has_param("name")) {
            $data["name"] = $request->get_param("name");
        }
        if ($request->has_param("phone")) {
            $data["phone"] = $request->get_param("phone");
        }
        if ($request->has_param("email")) {
            $data["email"] = $request->get_param("email");
        }
        if ($request->has_param("address")) {
            $data["address"] = $request->get_param("address");
        }

        try {
            $result = $this->updateProfile->execute($customerId, $data);

            return new WP_REST_Response([
                "success" => true,
                "message" => "Customer updated successfully",
                "data" => $result,
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                "success" => false,
                "error" => $e->getMessage(),
            ], 400);
        }
    }

    public function get_customer_contracts(WP_REST_Request $request): WP_REST_Response
    {
        $customerId = (int) $request->get_param("id");

        try {
            $result = $this->getContracts->execute($customerId);

            return new WP_REST_Response([
                "success" => true,
                "data" => $result,
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                "success" => false,
                "error" => $e->getMessage(),
            ], 404);
        }
    }

    public function get_customer_briefings(WP_REST_Request $request): WP_REST_Response
    {
        $customerId = (int) $request->get_param("id");

        try {
            $result = $this->getBriefings->execute($customerId);

            return new WP_REST_Response([
                "success" => true,
                "data" => $result,
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                "success" => false,
                "error" => $e->getMessage(),
            ], 404);
        }
    }

    // Permission callbacks

    public function check_admin_permission(WP_REST_Request $request): bool
    {
        return \current_user_can("manage_options");
    }

    public function check_authenticated(WP_REST_Request $request): bool
    {
        $userId = $request->get_param("_user_id") ?: \get_current_user_id();
        return $userId > 0;
    }

    public function check_customer_or_admin_permission(WP_REST_Request $request): bool
    {
        $customerId = (int) $request->get_param("id");
        $currentUserId = $request->get_param("_user_id") ?: \get_current_user_id();

        // Admin pode acessar qualquer cliente
        if (\current_user_can("manage_options")) {
            return true;
        }

        // Cliente pode acessar apenas seu próprio perfil
        return $currentUserId === $customerId;
    }
}
