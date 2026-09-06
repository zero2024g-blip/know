// ============================================================================
//  obf.h — compile-time string hiding. The REAL text never appears in the
//  binary; only scrambled bytes do. Source stays readable: OBF("game").
//
//  How: each string is XOR-encrypted at COMPILE time with a per-use key, so
//  `.rodata` holds ciphertext. At runtime it is decrypted into a std::string
//  through a `volatile` key, which stops the optimizer from folding the
//  plaintext back in. Verified: `strings`/grep of the binary does not show the
//  real text.
//
//  Cost layer, not a wall: a debugger can still watch the decrypted value in
//  memory at use. It stops static string dumps (IDA/AI reading .rodata) and
//  makes locating a check by its message much harder.
// ============================================================================
#ifndef EAGLE_OBF_H
#define EAGLE_OBF_H

#include <string>
#include <cstddef>
#include <utility>

namespace obf {

constexpr unsigned mixseed(unsigned s) {
    s ^= 0x9e3779b9u; s *= 2654435761u; s ^= s >> 15; return s;
}
constexpr char keybyte(unsigned seed, std::size_t i) {
    unsigned k = mixseed(seed + (unsigned)(i * 0x1000193u));
    return (char)((k >> ((i & 3) * 8)) & 0xFF);
}

template <std::size_t N>
struct Hidden {
    char buf[N];
    unsigned seed;

    template <std::size_t... I>
    constexpr Hidden(const char (&s)[N], unsigned sd, std::index_sequence<I...>)
        : buf{ (char)(s[I] ^ keybyte(sd, I))... }, seed(sd) {}

    // Runtime decrypt. `vk` is volatile so the compiler cannot precompute this
    // and re-embed the plaintext.
    std::string reveal() const {
        std::string o;
        o.resize(N - 1);
        volatile unsigned vk = seed;
        unsigned k = vk;
        for (std::size_t i = 0; i < N - 1; ++i) {
            o[i] = (char)(buf[i] ^ keybyte(k, i));
        }
        return o;
    }
};

template <std::size_t N>
constexpr Hidden<N> make(const char (&s)[N], unsigned seed) {
    return Hidden<N>(s, seed, std::make_index_sequence<N>{});
}

} // namespace obf

// A different key per call site (from __LINE__ and __COUNTER__), so identical
// strings encrypt differently and there is no constant pattern to scan for.
#define OBF(str) ([]() -> std::string {                                        \
        constexpr unsigned S = ((unsigned)(__LINE__) * 2246822519u)            \
                             ^ ((unsigned)(__COUNTER__) * 3266489917u)         \
                             ^ 0x27d4eb2fu;                                     \
        constexpr auto h = ::obf::make(str, S);                                \
        return h.reveal();                                                     \
    }())

#endif
