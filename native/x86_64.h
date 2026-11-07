/* SPDX-FileCopyrightText: 2026 PolyvanthOS contributors */
/* SPDX-License-Identifier: GPL-2.0-only */

/*
 * The C side of the x86-64 classes: the few CPU instructions that PHP cannot express.
 * Another architecture gets its own header with the same function names.
 * The names follow the compiler's rule: Namespace_Class_method.
 */
#pragma once

#include <stdint.h>

/* Table of exception stub addresses, defined in ISR.S. */
extern const uint64_t isr_stub_table[32];

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

static inline void Arch_X86_64_CPU_cli(void) {
    __asm__ volatile("cli");
}

static inline void Arch_X86_64_CPU_hlt(void) {
    __asm__ volatile("hlt");
}

/* lgdt and lidt read a 10-byte structure (2-byte limit, 8-byte base) at the given address. */
static inline void Arch_X86_64_CPU_lgdt(uint64_t pointer) {
    __asm__ volatile("lgdt (%0)" : : "r"(pointer) : "memory");
}

static inline void Arch_X86_64_CPU_lidt(uint64_t pointer) {
    __asm__ volatile("lidt (%0)" : : "r"(pointer) : "memory");
}

/* lgdt does not touch the segment registers, so reload the data ones from the new table. */
static inline void Arch_X86_64_CPU_loadDataSegments(uint16_t selector) {
    __asm__ volatile("mov %w0, %%ds\n"
                     "mov %w0, %%es\n"
                     "mov %w0, %%fs\n"
                     "mov %w0, %%gs\n"
                     "mov %w0, %%ss\n"
                     :
                     : "r"(selector)
                     : "memory");
}

/*
 * cs cannot be set with mov. Push the new cs and the address of label 1, then far return,
 * which pops both.
 */
static inline void Arch_X86_64_CPU_loadCodeSegment(uint16_t selector) {
    __asm__ volatile("pushq %0\n"
                     "leaq 1f(%%rip), %%rax\n"
                     "pushq %%rax\n"
                     "lretq\n"
                     "1:\n"
                     :
                     : "r"((uint64_t)selector)
                     : "rax", "memory");
}

/* Polyvanthos\Arch\X86_64\ISR */

static inline uint64_t Arch_X86_64_ISR_stub(int64_t vector) {
    return isr_stub_table[vector];
}
