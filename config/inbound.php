<?php

return ['enabled_providers' => array_values(array_filter(explode(',', env('INBOUND_ENABLED_PROVIDERS', 'fake'))))];
