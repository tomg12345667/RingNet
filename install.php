<?php
/**
 * Call Blast - Install Script
 *
 * Runs automatically when the module is installed or upgraded via
 * fwconsole ma install callblast  (or Module Admin in the GUI).
 *
 * Responsibilities:
 *  1. Write the [callblast-playback] dialplan context to extensions_custom.conf
 *  2. Reload the Asterisk dialplan so the context is live immediately
 */

// The FreePBX bootstrap provides the \FreePBX object at this point.
$cb = \FreePBX::create()->Callblast;
$cb->writeDialplan();
