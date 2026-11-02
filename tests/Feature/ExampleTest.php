<?php

declare(strict_types=1);

it('redirects the home page to the dashboard', function () {
    $this->get(route('home'))->assertRedirect('/dashboard');
});
