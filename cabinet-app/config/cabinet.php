<?php

// Données de référence reprises du prototype HTML (plan comptable, questionnaires, barèmes…)
return json_decode(file_get_contents(__DIR__.'/cabinet_data.json'), true);
