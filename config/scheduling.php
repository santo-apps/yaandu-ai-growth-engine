<?php

return ['enabled_providers' => array_values(array_filter(explode(',', env('SCHEDULING_ENABLED_PROVIDERS', 'fake'))))];
