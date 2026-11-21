<?php

namespace App\Services;

use RuntimeException;

/** Le document ne peut pas être lu automatiquement : il attend une saisie manuelle dans son module (statut « à saisir »). */
class SansLecture extends RuntimeException {}
