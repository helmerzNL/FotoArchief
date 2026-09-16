# Foto's koppelen

Het veld **Aanwinstnummer of Foto-ID** bij collecties, personen, locaties,
bronnen en bijdragers accepteert een exact aanwinstnummer of een volledige
ULID van 26 tekens. Foto-ID's mogen in hoofdletters of kleine letters worden
geplakt; spaties voor en na de invoer worden verwijderd.

Een exact aanwinstnummer heeft voorrang wanneer het zelf op een ULID lijkt.
De afzonderlijke `asset_id`-parameter blijft uitsluitend een ID-verwijzing.
Een ontbrekende foto geeft een validatiefout. Koppelen vereist zowel lees-
als bewerktoegang tot de betreffende foto; een Foto-ID omzeilt geen eigendom.
