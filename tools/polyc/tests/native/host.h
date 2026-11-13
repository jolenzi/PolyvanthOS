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

/* The same rules as Kprintf: every argument is one 64-bit word. Knows %% %d %u %s and the l modifier. */
static inline void Host_format(const char *fmt, int64_t count, const uint64_t *args)
{
    int64_t next = 0;
    for (const char *p = fmt; *p != '\0'; p++) {
        if (*p != '%') {
            putchar(*p);
            continue;
        }
        p++;
        int is_long = 0;
        while (*p == 'l') {
            is_long = 1;
            p++;
        }
        if (*p == '\0') {
            break;
        }
        if (*p == '%') {
            putchar('%');
            continue;
        }
        uint64_t word = next < count ? args[next] : 0;
        next++;
        switch (*p) {
        case 'd':
            if (is_long) {
                printf("%lld", (long long)(int64_t)word);
            } else {
                printf("%d", (int)(int32_t)word);
            }
            break;
        case 'u':
            if (is_long) {
                printf("%llu", (unsigned long long)word);
            } else {
                printf("%u", (unsigned)(uint32_t)word);
            }
            break;
        case 's':
            fputs(word != 0 ? (const char *)(uintptr_t)word : "(null)", stdout);
            break;
        default:
            putchar('%');
            putchar(*p);
            break;
        }
    }
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
