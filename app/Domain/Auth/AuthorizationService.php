<?php

namespace App\Domain\Auth;

final class AuthorizationService
{
    public function can(array $roles): bool
    {
        $currentRole = strtolower(trim((string)($_SESSION['user']['role'] ?? '')));
        if ($currentRole === '') {
            return false;
        }

        foreach ($roles as $role) {
            if ($currentRole === strtolower(trim((string)$role))) {
                return true;
            }
        }

        return false;
    }

    public function assertAnyRole(array $roles): void
    {
        if ($this->can($roles)) {
            return;
        }

        if (function_exists('log_message')) {
            log_message('warning', 'Unauthorized role access', [
                'required_roles' => $roles,
                'actual_role' => $_SESSION['user']['role'] ?? null,
                'uri' => $_SERVER['REQUEST_URI'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_id' => $_SESSION['user']['id'] ?? null,
            ]);
        }

        if (function_exists('render_error')) {
            render_error('You are not authorized to access this page.', 403);
        }

        http_response_code(403);
        exit('Forbidden');
    }
}

