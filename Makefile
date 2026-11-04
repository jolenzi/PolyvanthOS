# SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
# SPDX-License-Identifier: GPL-2.0-only

PHP = php

# Compile test programs with polyc, run them on this computer, and check the compiler
# rejects bad programs
test-compiler:
	$(PHP) tools/polyc/tests/run.php

clean:
	rm -rf build

.PHONY: test-compiler clean
