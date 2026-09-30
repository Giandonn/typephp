#include <windows.h>
#include <cstdio>
#include <cstring>

int main(int argc, char **argv)
{
    if (argc != 3) return 1;
    HMODULE library = LoadLibraryA(argv[1]);
    if (library == nullptr) {
        std::fprintf(stderr, "LoadLibrary failed: %lu\n", GetLastError());
        return 2;
    }
    auto init = reinterpret_cast<int (*)(int, char **)>(GetProcAddress(library, "typephp_clang_matrix_lib_runtime_init"));
    auto shutdown = reinterpret_cast<void (*)()>(GetProcAddress(library, "typephp_clang_matrix_lib_runtime_shutdown"));
    auto add = reinterpret_cast<long long (*)(long long, long long)>(GetProcAddress(library, "clang_matrix_add"));
    auto state = reinterpret_cast<long long (*)(long long)>(GetProcAddress(library, "clang_matrix_state"));
    auto zts = reinterpret_cast<int (*)()>(GetProcAddress(library, "clang_matrix_zts"));
    if (!init || !shutdown || !add || !state || !zts) return 3;
    if (init(argc, argv) != 0) return 4;
    if (add(40, 2) != 42 || state(20) != 20 || state(22) != 42) return 5;
    int expectedZts = std::strcmp(argv[2], "zts") == 0 ? 1 : 0;
    if (zts() != expectedZts) return 6;
    shutdown();
    FreeLibrary(library);
    std::printf("lib-ok:%s\n", argv[2]);
    return 0;
}
