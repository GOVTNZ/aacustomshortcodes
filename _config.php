<?php

// override ShortcodeParser with the custom element parser
Object::useCustomClass('ShortcodeParser', 'GovtNZShortcodeParser', true);
