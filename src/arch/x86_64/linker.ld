/* SPDX-FileCopyrightText: 2026 PolyvanthOS contributors */
/* SPDX-License-Identifier: GPL-2.0-only */

ENTRY(kmain)
SECTIONS {
  . = 0xFFFFFFFF80000000;
  .text   : { *(.text*) }
  . = ALIGN(4K);
  .rodata : { *(.rodata*) }
  . = ALIGN(4K);
  .data   : {
    KEEP(*(.limine_requests_start))
    KEEP(*(.limine_requests))
    KEEP(*(.limine_requests_end))
    *(.data*)
  }
  .bss    : { *(.bss*) *(COMMON) }
}