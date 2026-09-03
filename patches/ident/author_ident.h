// ===========================================================================
//  author_ident.h — stamp your name into the .so, next to the ELF header.
//  Include this at the TOP of your FIRST source file (so it lands early).
//  Cosmetic signature only — a cracker can `strip -R .comment`; for real,
//  tamper-proof attribution use the forensic watermark (wm_tool).
// ===========================================================================
#ifndef AUTHOR_IDENT_H
#define AUTHOR_IDENT_H

// 1) .comment — sits right beside the "Android clang version ..." strings.
__asm__(".ident \"created by: Z E R O ~ @Kingdarkpro\"");

// 2) an ELF NOTE — notes live in PT_NOTE, near the very start of the file
//    (just after the ELF header / program headers).
__asm__(
    ".pushsection .note.zero,\"a\",%note\n"
    ".balign 4\n"
    ".long 2f-1f\n"            /* name size */
    ".long 4f-3f\n"            /* desc size */
    ".long 0x5A45524F\n"      /* type = 'ZERO' */
    "1:.asciz \"ZERO\"\n2:\n.balign 4\n"
    "3:.asciz \"created by: Z E R O ~ @Kingdarkpro\"\n4:\n.balign 4\n"
    ".popsection\n"
);

// 3) a visible, kept rodata string (shows in `strings` no matter what).
__attribute__((used))
volatile const char ZERO_SIGNATURE[] = "created by: Z E R O ~ @Kingdarkpro";

#endif
