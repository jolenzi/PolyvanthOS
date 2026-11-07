/* SPDX-FileCopyrightText: 2026 PolyvanthOS contributors */
/* SPDX-License-Identifier: GPL-2.0-only */

/*
 * Exception entry stubs for vectors 0-31.
 * Each stub pushes the vector number, and a 0 error code if the CPU didn't push one.
 */

.section .text

/* No error code from the CPU: push a fake 0 so the layout matches */
.macro ISR_NOERR n
isr\n:
    pushq $0            /* fake error code */
    pushq $\n           /* vector number */
    jmp isr_common
.endm

/* The CPU already pushed an error code */
.macro ISR_ERR n
isr\n:
    pushq $\n           /* vector number */
    jmp isr_common
.endm

ISR_NOERR 0
ISR_NOERR 1
ISR_NOERR 2
ISR_NOERR 3
ISR_NOERR 4
ISR_NOERR 5
ISR_NOERR 6
ISR_NOERR 7
ISR_ERR   8
ISR_NOERR 9
ISR_ERR   10
ISR_ERR   11
ISR_ERR   12
ISR_ERR   13
ISR_ERR   14
ISR_NOERR 15
ISR_NOERR 16
ISR_ERR   17
ISR_NOERR 18
ISR_NOERR 19
ISR_NOERR 20
ISR_ERR   21
ISR_NOERR 22
ISR_NOERR 23
ISR_NOERR 24
ISR_NOERR 25
ISR_NOERR 26
ISR_NOERR 27
ISR_NOERR 28
ISR_ERR   29
ISR_ERR   30
ISR_NOERR 31

/* Shared by every stub: save the registers, then call exception_handler(frame) in IDT.php */
isr_common:
    /* Save every general register. r15 ends up lowest, matching InterruptFrame */
    pushq %rax
    pushq %rbx
    pushq %rcx
    pushq %rdx
    pushq %rsi
    pushq %rdi
    pushq %rbp
    pushq %r8
    pushq %r9
    pushq %r10
    pushq %r11
    pushq %r12
    pushq %r13
    pushq %r14
    pushq %r15

    movq %rsp, %rdi     /* 1st argument: pointer to the saved registers */
                        /* 22 values (176 bytes) pushed, so rsp is already 16-byte aligned */
    cld                 /* the calling rules expect the direction flag clear */
    call exception_handler
1:
    hlt                 /* exception_handler never returns, but stop here just in case */
    jmp 1b

/* Stub addresses, in vector order, for IDT.php */
.section .rodata
.global isr_stub_table
isr_stub_table:
    .quad isr0,  isr1,  isr2,  isr3,  isr4,  isr5,  isr6,  isr7
    .quad isr8,  isr9,  isr10, isr11, isr12, isr13, isr14, isr15
    .quad isr16, isr17, isr18, isr19, isr20, isr21, isr22, isr23
    .quad isr24, isr25, isr26, isr27, isr28, isr29, isr30, isr31
