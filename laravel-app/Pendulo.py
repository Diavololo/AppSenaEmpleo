import tkinter as tk
import math
import time

# Constantes físicas aproximadas
G = 9.81          # gravedad (m/s^2)
L_FISICA = 1.0    # longitud del péndulo en metros (para el cálculo del período)

# Parámetros de dibujo
ANCHO_VENTANA = 600
ALTO_VENTANA = 400
LONGITUD_PIXELES = 200
RADIO_BOLA = 20


def pedir_angulo_inicial():
    print("Simulación de un péndulo simple")
    print("El ángulo se mide en grados, respecto a la vertical.")
    entrada = input("Ingrese el ángulo inicial (por ejemplo 30 o -30): ")

    try:
        angulo = float(entrada)
    except ValueError:
        print("Entrada no válida. Se usará 20 grados por defecto.")
        angulo = 20.0

    if abs(angulo) > 80:
        if angulo > 0:
            angulo = 80.0
        else:
            angulo = -80.0
        print("Se limitó el ángulo a", angulo, "grados para una animación estable.")

    return math.radians(angulo)


def main():
    angulo_inicial = pedir_angulo_inicial()

    ventana = tk.Tk()
    ventana.title("Péndulo simple")

    lienzo = tk.Canvas(ventana, width=ANCHO_VENTANA, height=ALTO_VENTANA, bg="white")
    lienzo.pack()

    # Punto de suspensión en la parte superior central
    x_pivote = ANCHO_VENTANA // 2
    y_pivote = 50

    # Línea vertical de referencia (la vertical roja de la imagen del enunciado)
    lienzo.create_line(x_pivote, y_pivote, x_pivote, y_pivote + LONGITUD_PIXELES, fill="red", width=2)

    # Posición inicial del péndulo
    x_bola = x_pivote + LONGITUD_PIXELES * math.sin(angulo_inicial)
    y_bola = y_pivote + LONGITUD_PIXELES * math.cos(angulo_inicial)

    linea_pendulo = lienzo.create_line(
        x_pivote, y_pivote, x_bola, y_bola, fill="black", width=3
    )

    bola = lienzo.create_oval(
        x_bola - RADIO_BOLA, y_bola - RADIO_BOLA,
        x_bola + RADIO_BOLA, y_bola + RADIO_BOLA,
        fill="gray"
    )

    w = math.sqrt(G / L_FISICA)  # frecuencia angular del péndulo simple
    t0 = time.time()
    dt_ms = 20  # tiempo entre cuadros en milisegundos (aprox 50 FPS)

    def actualizar():
        t = time.time() - t0
        angulo = angulo_inicial * math.cos(w * t)

        x = x_pivote + LONGITUD_PIXELES * math.sin(angulo)
        y = y_pivote + LONGITUD_PIXELES * math.cos(angulo)

        lienzo.coords(linea_pendulo, x_pivote, y_pivote, x, y)
        lienzo.coords(
            bola,
            x - RADIO_BOLA, y - RADIO_BOLA,
            x + RADIO_BOLA, y + RADIO_BOLA
        )

        ventana.after(dt_ms, actualizar)

    actualizar()
    ventana.mainloop()


if __name__ == "__main__":
    main()
