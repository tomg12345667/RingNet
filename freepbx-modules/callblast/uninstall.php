<?php
/**
 * Call Blast - Uninstall Script
 *
 * Runs automatically when the module is uninstalled via
 * fwconsole ma uninstall callblast  (or Module Admin in the GUI).
 *
 * Responsibilities:
 *  1. Remove the [callblast-playback] context from extensions_custom.conf
 *  2. Reload the Asterisk dialplan to apply the removal
 */

$cb = \FreePBX::create()->Callblast;
$cb->removeDialplan();
