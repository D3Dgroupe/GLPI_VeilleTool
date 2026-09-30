<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Récipient qui refuse de dépasser une taille.
 *
 * Contrôler la taille d'un document **après** l'avoir reçu ne protège de
 * rien : le serveur distant a déjà eu le loisir d'envoyer ce qu'il voulait,
 * et c'est la mémoire de PHP qui en a fait les frais.
 * Un flux hostile - ou simplement un fichier déposé par erreur à la place du
 * flux - suffit alors à faire tomber la collecte entière sur un dépassement
 * de mémoire.
 *
 * Le plafond doit donc s'appliquer pendant le transfert. cURL écrit ce qu'il
 * reçoit au fur et à mesure ; une écriture qui ne consomme pas tout ce qu'on
 * lui présente interrompt la connexion sur-le-champ. C'est ce que fait
 * `write()` une fois le plafond atteint : le reste du document n'est jamais
 * demandé.
 *
 * L'accord de compression est traité au passage : les octets comptés sont
 * ceux **après** décompression, ce qui ferme la porte au document de quelques
 * kilo-octets qui s'épanouit en plusieurs giga-octets.
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** @var StreamInterface Flux réellement écrit */
    private StreamInterface $stream;

    /** Nombre d'octets déjà écrits */
    private int $written = 0;

    /** Le plafond a-t-il été atteint ? */
    private bool $exceeded = false;

    /**
     * @param StreamInterface $stream Flux à protéger
     * @param int             $limit  Plafond, en octets
     */
    public function __construct(StreamInterface $stream, private readonly int $limit)
    {
        $this->stream = $stream;
    }

    /**
     * @param string $string
     *
     * @return int Nombre d'octets acceptés ; en deçà de la longueur
     *             présentée, cURL interrompt le transfert
     */
    public function write($string): int
    {
        $length = strlen($string);

        if ($this->written + $length > $this->limit) {
            $this->exceeded = true;

            return 0;
        }

        $this->written += $length;

        return $this->stream->write($string);
    }

    /**
     * Le transfert a-t-il été interrompu pour cause de dépassement ?
     *
     * C'est la seule façon de distinguer cette interruption d'une coupure
     * de réseau : cURL rapporte les deux comme une écriture impossible.
     *
     * @return bool
     */
    public function hasExceeded(): bool
    {
        return $this->exceeded;
    }
}
