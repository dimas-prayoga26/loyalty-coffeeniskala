<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_home_page_sends_customers_to_verification(): void
    {
        $this->get('/')->assertRedirect('/verify');
    }
}
