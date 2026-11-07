# SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
# SPDX-License-Identifier: GPL-2.0-only

PHP      = php
CC       = clang
POLYC     = tools/polyc/polyc
CFLAGS    = --target=x86_64-unknown-none-elf -std=gnu11 -O2 -g -Wall -Wextra \
            -ffreestanding -fno-stack-protector -fno-pic \
            -mno-red-zone -mcmodel=kernel -mno-sse -mno-mmx -Inative -Ibuild/gen

PHP_SRCS = $(shell find src -name '*.php')
GEN_C    = $(PHP_SRCS:src/%.php=build/gen/%.c)
ASMS     = $(shell find src -name '*.S')
OBJS     = $(GEN_C:build/gen/%.c=build/%.o) $(ASMS:src/%.S=build/%.o)
LINKER   = src/arch/x86_64/linker.ld
ISO      = polyvanthos.iso

all: $(ISO)

# polyc compiles all the PHP in one run. It writes polyc.h and one .c file per source file, and
# leaves a file alone when its contents did not change, so only real changes are rebuilt.
build/gen/.stamp: $(PHP_SRCS) $(shell find tools/polyc/src -name '*.php') $(POLYC)
	@mkdir -p build/gen
	$(PHP) $(POLYC) --psr4 Polyvanthos=src --out build/gen $(PHP_SRCS)
	@touch $@

$(GEN_C) build/gen/polyc.h: build/gen/.stamp ;

kernel: $(OBJS) $(LINKER)
	ld.lld -nostdlib -static -z max-page-size=0x1000 -T $(LINKER) -o $@ $(OBJS)

build/%.o: build/gen/%.c build/gen/polyc.h $(wildcard native/*.h)
	@mkdir -p $(@D)
	$(CC) $(CFLAGS) -c $< -o $@

build/%.o: src/%.S
	@mkdir -p $(@D)
	$(CC) --target=x86_64-unknown-none-elf -c $< -o $@

$(ISO): kernel limine.conf
	mkdir -p iso/boot/limine iso/EFI/BOOT
	cp kernel iso/boot/
	cp limine.conf limine/limine-bios.sys limine/limine-bios-cd.bin \
	   limine/limine-uefi-cd.bin iso/boot/limine/
	cp limine/BOOTX64.EFI iso/EFI/BOOT/
	xorriso -as mkisofs -R -r -J -b boot/limine/limine-bios-cd.bin \
	  -no-emul-boot -boot-load-size 4 -boot-info-table -hfsplus \
	  -apm-block-size 2048 --efi-boot boot/limine/limine-uefi-cd.bin \
	  -efi-boot-part --efi-boot-image --protective-msdos-label iso -o $@
	./limine/limine bios-install $@

run: $(ISO)
	qemu-system-x86_64 -cdrom $(ISO) -serial stdio -display none

clean:
	rm -rf build kernel iso $(ISO)

# Formatting, syntax and licensing checks
NATIVE_HEADERS = $(wildcard native/*.h)
# The programs in tools/polyc/tests/errors are wrong on purpose, some of them even for PHP itself
TOOL_PHP       = $(shell find tools -name '*.php' -not -path '*/tests/errors/*') $(POLYC)

check:
	clang-format --dry-run --Werror $(NATIVE_HEADERS)
	@for f in $(PHP_SRCS) $(TOOL_PHP); do $(PHP) -l $$f > /dev/null || exit 1; done
	reuse lint

# Compile test programs with polyc, run them on this computer, and check the compiler
# rejects bad programs
test-compiler:
	$(PHP) tools/polyc/tests/run.php

# Boot in QEMU and check the selftest finishes without a panic
test: $(ISO)
	@mkdir -p build
	@rm -f build/serial.log
	-timeout 15 qemu-system-x86_64 -cdrom $(ISO) -serial file:build/serial.log -display none -no-reboot
	@if grep -q -- "--- end selftest ---" build/serial.log && ! grep -q "PANIC" build/serial.log; then \
		echo "selftest passed"; \
	else \
		cat build/serial.log; echo "selftest FAILED"; exit 1; \
	fi

.PHONY: all run clean check test-compiler test
