import sqlite3

def main():
    print("Chargement de la base de données...")
    con = sqlite3.connect(":memory:")
    
    with open("base_rendez_vous.sql", "r", encoding="utf-8") as f:
        con.executescript(f.read())
        
    cur = con.cursor()

    print("\n" + "="*70)
    print("REQUÊTE 1 : Rendez-vous confirmés à venir pris un samedi ou dimanche")
    print("="*70)
    cur.execute("""
        SELECT id, npi, service, date_heure, statut 
        FROM rendez_vous 
        WHERE statut = 'confirme' 
          AND date_heure > '2026-11-20 18:00:00'
          AND strftime('%w', date_heure) IN ('0', '6')
        ORDER BY date_heure ASC
    """)
    q1 = cur.fetchall()
    print(f"Total trouvés : {len(q1)}")
    for r in q1:
        print(f"  ID: {r[0]} | NPI: {r[1]} | Service: {r[2]} | Date: {r[3]} | Statut: {r[4]}")

    print("\n" + "="*70)
    print("REQUÊTE 2 : Créneaux qui comptent plus de 3 rendez-vous non annulés")
    print("="*70)
    cur.execute("""
        SELECT date_heure, count(*) as nb_rdv
        FROM rendez_vous
        WHERE statut != 'annule'
        GROUP BY date_heure
        HAVING count(*) > 3
        ORDER BY date_heure ASC
    """)
    q2 = cur.fetchall()
    print(f"Total créneaux en surcapacité : {len(q2)}")
    for r in q2:
        print(f"  Créneau: {r[0]} | Nombre d'usagers actifs: {r[1]}")

    print("\n" + "="*70)
    print("REQUÊTE 3 : Rendez-vous passés du statut 'annule' à un autre statut")
    print("="*70)
    cur.execute("""
        SELECT h.rendez_vous_id, r.npi, r.service, r.date_heure, h.ancien_statut, h.nouveau_statut, h.modifie_le, h.modifie_par
        FROM historique_statuts h
        JOIN rendez_vous r ON r.id = h.rendez_vous_id
        WHERE h.ancien_statut = 'annule' AND h.nouveau_statut != 'annule'
        ORDER BY h.modifie_le ASC
    """)
    q3 = cur.fetchall()
    print(f"Total anomalies de statut trouvées : {len(q3)}")
    for r in q3:
        print(f"  RDV ID: {r[0]} | NPI: {r[1]} | {r[4]} -> {r[5]} le {r[6]} par {r[7]}")

    print("\n" + "="*70)
    print("BONUS : Rendez-vous hors grille horaire (08:00 à 15:30)")
    print("="*70)
    cur.execute("""
        SELECT id, npi, service, date_heure, statut
        FROM rendez_vous
        WHERE strftime('%H:%M', date_heure) < '08:00'
           OR strftime('%H:%M', date_heure) > '15:30'
           OR strftime('%M', date_heure) NOT IN ('00', '30')
        ORDER BY date_heure ASC
    """)
    bonus = cur.fetchall()
    print(f"Total hors grille : {len(bonus)}")
    for r in bonus:
        print(f"  ID: {r[0]} | NPI: {r[1]} | {r[2]} | {r[3]} | Statut: {r[4]}")
    print("="*70 + "\n")

if __name__ == "__main__":
    main()
