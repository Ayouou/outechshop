<?php
/**
 * Ancienne mise en service mono-société.
 *
 * Une installation OUTECH sert désormais plusieurs sociétés : c'est la console de
 * la plateforme qui crée leur espace (base, société, lieu et compte
 * administrateur), à l'acceptation. Cette adresse reste routée pour les
 * anciens signets et renvoie là où l'installation se fait maintenant.
 */

rediriger('/console/installation');
