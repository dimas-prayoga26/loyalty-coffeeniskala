<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_home_page_sends_customers_to_verification(): void
    {
        $this->get('/')->assertRedirect('/verify');
    }

    public function test_the_home_page_keeps_the_folder_the_app_is_served_from(): void
    {
        // Production serves public/ from coffeeniskala.com/loyalty (a symlink):
        // the request Apache hands over for https://coffeeniskala.com/loyalty/.
        $request = Request::create('http://localhost/loyalty/', 'GET', server: [
            'SCRIPT_NAME' => '/loyalty/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'),
        ]);

        TestResponse::fromBaseResponse($this->app->make(Kernel::class)->handle($request))
            ->assertFound()
            ->assertHeader('Location', 'http://localhost/loyalty/verify');
    }
}
