
<?php

$IMS_API_KEY = getenv('IMS_06a8ddf816377d8ec82541438516a9484fc18ab42febd2ee7e1713a91b73bc9e')
    ?: ($_SERVER['IMS_06a8ddf816377d8ec82541438516a9484fc18ab42febd2ee7e1713a91b73bc9e'] ?? '');

if (empty($IMS_API_KEY)) {
    error_log('IMS API Key is not configured.');
}

?>
