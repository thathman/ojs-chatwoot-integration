<?php
/**
 * @file plugins/generic/chatwootIntegration/index.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_chatwootIntegration
 *
 * @brief Wrapper for Chatwoot Integration plugin.
 *
 */

require_once __DIR__ . '/classes/v2/bootstrap.php';

return new \APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin();
