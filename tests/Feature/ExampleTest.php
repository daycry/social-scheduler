<?php

it('responds to the health endpoint', function () {
    $this->get('/up')->assertOk();
});
