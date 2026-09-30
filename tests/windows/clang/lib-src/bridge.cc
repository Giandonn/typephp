#include <phpx.h>

extern "C" __declspec(dllexport) long long clang_matrix_add(long long left, long long right)
{
    return php_clang_lib_add(left, right);
}

extern "C" __declspec(dllexport) long long clang_matrix_state(long long delta)
{
    return php_clang_lib_state(delta);
}

extern "C" __declspec(dllexport) int clang_matrix_zts()
{
#ifdef ZTS
    return php_clang_lib_zts() ? 1 : -1;
#else
    return php_clang_lib_zts() ? -1 : 0;
#endif
}
