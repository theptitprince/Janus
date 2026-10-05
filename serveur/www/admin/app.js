// app.js - Console des licences ETDEL : confirmation des actions d'ecriture, copie dans le
//          presse-papiers, duree par defaut, compteurs, controle d'exposition des fichiers.
// ETDEL (c) 2026
(function () {
  'use strict';

  // Toute ecriture demande une confirmation ; sans JavaScript, le serveur affiche sa propre page.
  document.addEventListener('submit', function (evenement) {
    var formulaire = evenement.target;
    var message = formulaire.getAttribute('data-confirmer');
    if (!message || !formulaire.elements.confirme || formulaire.elements.confirme.value === '1') {
      return;
    }
    if (!window.confirm(message + ' ?')) {
      evenement.preventDefault();
      return;
    }
    formulaire.elements.confirme.value = '1';
  });

  document.addEventListener('click', function (evenement) {
    var retour = evenement.target.closest('[data-retour]');
    if (retour && window.history.length > 1) {
      evenement.preventDefault();
      window.history.back();
      return;
    }
    var bouton = evenement.target.closest('[data-copier]');
    if (!bouton) {
      return;
    }
    var cible = document.getElementById(bouton.getAttribute('data-copier'));
    if (!cible) {
      return;
    }
    var signaler = function () {
      bouton.textContent = 'Copie';
      window.setTimeout(function () { bouton.textContent = 'Copier'; }, 1500);
    };
    var secours = function () {
      var plage = document.createRange();
      plage.selectNodeContents(cible);
      var selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(plage);
      try {
        if (document.execCommand('copy')) {
          signaler();
        }
      } catch (e) {
        // Le texte reste selectionne : copie manuelle possible.
      }
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(cible.textContent).then(signaler, secours);
    } else {
      secours();
    }
  });

  // Creation d'une cle : la duree proposee suit la distribution choisie.
  var choix = document.querySelector('select[data-durees]');
  if (choix && choix.form && choix.form.elements.duree_j) {
    choix.addEventListener('change', function () {
      var option = choix.options[choix.selectedIndex];
      choix.form.elements.duree_j.value = option ? (option.getAttribute('data-duree') || '') : '';
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('textarea[maxlength]'), function (zone) {
    var compteur = document.createElement('small');
    compteur.className = 'discret';
    var majour = function () { compteur.textContent = zone.value.length + ' / ' + zone.getAttribute('maxlength'); };
    zone.parentNode.appendChild(compteur);
    zone.addEventListener('input', majour);
    majour();
  });

  // Controle d'exposition : aucun de ces fichiers ne doit etre telechargeable par URL.
  var liste = document.getElementById('exposition');
  if (liste && window.fetch) {
    liste.getAttribute('data-chemins').split(' ').forEach(function (chemin) {
      var ligne = document.createElement('li');
      ligne.textContent = chemin + ' : verification...';
      liste.appendChild(ligne);
      var conclure = function (statut) {
        var expose = statut === 200;
        ligne.className = expose ? 'danger' : 'protege';
        ligne.textContent = chemin + ' : ' + (expose ? 'DANGER, telechargeable' : 'protege (' + (statut || 'refus') + ')');
      };
      window.fetch(chemin, { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
        .then(function (reponse) { conclure(reponse.status); }, function () { conclure(0); });
    });
  }
}());
