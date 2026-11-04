/* SPDX-FileCopyrightText: 2026 PolyvanthOS contributors */
/* SPDX-License-Identifier: GPL-2.0-only */

/* The native side of the compiler tests: lets test programs print on a normal computer. */
#pragma once

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>

static inline void Host_int(int64_t v)
{
    printf("%lld", (long long)v);
}

static inline void Host_uint(uint64_t v)
{
    printf("%llu", (unsigned long long)v);
}

static inline void Host_hex(uint64_t v)
{
    printf("%llx", (unsigned long long)v);
}

static inline void Host_str(const char *s)
{
    fputs(s, stdout);
}

static inline void Host_nl(void)
{
    putchar('\n');
}

static inline uint64_t Host_alloc(uint64_t size)
{
    return (uint64_t)(uintptr_t)calloc(1, size);
}

static inline uint8_t Mem_read8(uint64_t addr)
{
    return *(volatile uint8_t *)(uintptr_t)addr;
}

static inline void Mem_write8(uint64_t addr, uint8_t value)
{
    *(volatile uint8_t *)(uintptr_t)addr = value;
}
