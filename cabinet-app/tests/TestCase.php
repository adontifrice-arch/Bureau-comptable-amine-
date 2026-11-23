<?php

namespace Tests;

use App\Services\Compta;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Contrôle « aucune pièce avec un compte absent du plan comptable ». Les tests anciens passent des écritures
     * sur des sous-comptes non créés (401001, 6061…) : il n'y est pas appliqué ; les tests du contrôle le mettent à true.
     */
    protected bool $controleComptes = false;

    protected function setUp(): void
    {
        parent::setUp();
        Compta::$controleComptes = $this->controleComptes;
    }

    protected function tearDown(): void
    {
        Compta::$controleComptes = true;
        parent::tearDown();
    }
}
