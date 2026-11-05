/* SPDX-FileCopyrightText: 2026 PolyvanthOS contributors */
/* SPDX-License-Identifier: GPL-2.0-only */

/*
 * The C side of the x86-64 classes: the few CPU instructions that PHP cannot express.
 * Another architecture gets its own header with the same function names.
 * The names follow the compiler's rule: Namespace_Class_method.
 */
#pragma once

#include <stdint.h>

/* Polyvanthos\Arch\X86_64\Port */

static inline void Arch_X86_64_Port_outb(uint16_t port, uint8_t value) {
    __asm__ volatile("outb %0, %1" : : "a"(value), "Nd"(port) : "memory");
}

static inline uint8_t Arch_X86_64_Port_inb(uint16_t port) {
    uint8_t result;
    __asm__ volatile("inb %1, %0" : "=a"(result) : "Nd"(port) : "memory");
    return result;
}

/* Polyvanthos\Arch\X86_64\CPU */

static inline void Arch_X86_64_CPU_hlt(void) {
    __asm__ volatile("hlt");
}
