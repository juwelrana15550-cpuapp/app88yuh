<?php
// The shop now lives on the Home page (dashboard.php). Old links keep working.
header('Location: /dashboard.php', true, 301);
exit;
