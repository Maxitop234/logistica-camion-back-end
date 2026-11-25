#include "reloj.h"

#include <time.h>

#include "config.h"

bool relojEnHora() { return time(nullptr) >= static_cast<time_t>(EPOCH_VALIDO_MIN); }

uint32_t ahoraEpoch() { return relojEnHora() ? static_cast<uint32_t>(time(nullptr)) : 0; }
