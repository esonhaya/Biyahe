<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    public function test_admin_panel_redirects_guests_to_authenticated_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
