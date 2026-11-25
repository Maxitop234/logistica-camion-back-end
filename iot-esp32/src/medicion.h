// Medición del HC-SR04 y de la batería. No depende del transporte.
#pragma once

// Configura los pines del sensor y del ADC.
void iniciarMedicion();

// Mediana de las muestras con eco (cm); NAN si ninguna lo tuvo.
float medirDistanciaCm();

// Voltaje de la batería tras el divisor; NAN si no hay lectura creíble.
float medirBateriaV();
