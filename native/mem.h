/* SPDX-FileCopyrightText: 2026 PolyvanthOS contributors */
/* SPDX-License-Identifier: GPL-2.0-only */

/*
 * The C side of Polyvanthos\Mem: reading and writing memory at an address.
 *
 * Every access is volatile, so the compiler never skips, merges or reorders them. That is what
 * you want for device memory, and it costs nothing that matters for ordinary RAM here.
 * The names follow the compiler's rule: Class_method.
 */
#pragma once

#include <stdint.h>

/* One reader and one writer for an access that is BITS wide. */
#define MEM_ACCESSORS(BITS)                                                                        \
    static inline uint##BITS##_t Mem_read##BITS(uint64_t address) {                                \
        volatile uint##BITS##_t *cell = (volatile uint##BITS##_t *)(uintptr_t)address;             \
        return *cell;                                                                              \
    }                                                                                              \
    static inline void Mem_write##BITS(uint64_t address, uint##BITS##_t value) {                   \
        volatile uint##BITS##_t *cell = (volatile uint##BITS##_t *)(uintptr_t)address;             \
        *cell = value;                                                                             \
    }

MEM_ACCESSORS(8)
MEM_ACCESSORS(16)
MEM_ACCESSORS(32)
MEM_ACCESSORS(64)

#undef MEM_ACCESSORS
