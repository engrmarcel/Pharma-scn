<?php

namespace App\Domain\Integration\Sap;

use App\Domain\Integration\Files\XmlDocument;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Support\Exceptions\BusinessRuleViolation;
use SimpleXMLElement;

/**
 * SAP IDoc (XML) adapter. SAP sends IDocs as XML over HTTP through its own
 * middleware (SAP Integration Suite / Cloud Integration, PI/PO, or an XML-HTTP
 * port, depending on the landscape). The platform never connects to SAP
 * directly and needs no RFC or database access.
 *
 * Each <IDOC> becomes one inbox record:
 *   message_id  "IDOC-<DOCNUM>" from the control record EDI_DC40 (unique per SAP system)
 *   entity      from the message type (MESTYP): MATMAS → product, BATMAS → batch, or
 *               settings.idoc_message_types on the connector
 *   record      the first data segment, flattened: its fields as keys, child segments as
 *               lists under the segment name (e.g. E1MAKTM.0.MAKTX)
 *
 * Field mapping then applies as for any other source; the sap_idoc_* mapping
 * templates are the starting point. IDoc definitions vary with release and
 * customer extensions, so confirm the segments in the customer's system
 * (transaction WE60) before go-live.
 */
class SapIdocAdapter
{
    public const MESSAGE_TYPES = ['MATMAS' => 'product', 'BATMAS' => 'batch'];

    /** @return array{envelopes: list<array>, rejected: list<array>} */
    public function parse(IntegrationConnector $connector, string $xml): array
    {
        $types = ($connector->setting('idoc_message_types') ?? []) + self::MESSAGE_TYPES;
        $idocs = XmlDocument::load($xml)->xpath('//IDOC') ?: [];
        if ($idocs === []) {
            throw new BusinessRuleViolation('idoc_empty', 'No <IDOC> element found. Send the IDoc XML as produced by the SAP XML port.', 422);
        }

        $envelopes = [];
        $rejected = [];
        foreach ($idocs as $idoc) {
            $control = $idoc->EDI_DC40;
            $docnum = ltrim(trim((string) ($control->DOCNUM ?? '')), '0');
            $type = strtoupper(trim((string) ($control->MESTYP ?? '')));
            $messageId = $docnum === '' ? null : "IDOC-{$docnum}";

            if ($messageId === null) {
                $rejected[] = ['status' => 'rejected', 'error' => ['code' => 'idoc_control_invalid', 'message' => 'The IDoc has no EDI_DC40/DOCNUM.']];

                continue;
            }
            if (! isset($types[$type])) {
                $rejected[] = ['message_id' => $messageId, 'status' => 'rejected', 'error' => ['code' => 'idoc_type_unsupported',
                    'message' => "Message type '{$type}' is not mapped to a canonical entity.", 'details' => ['supported' => array_keys($types)]]];

                continue;
            }

            $segment = null;
            foreach ($idoc->children() as $name => $child) {
                if ($name !== 'EDI_DC40') {
                    $segment = $child;
                    break;
                }
            }
            if ($segment === null) {
                $rejected[] = ['message_id' => $messageId, 'status' => 'rejected', 'error' => ['code' => 'idoc_empty', 'message' => 'The IDoc has no data segment.']];

                continue;
            }

            $envelopes[] = ['message_id' => $messageId, 'entity_type' => $types[$type],
                'record' => $this->flatten($segment) + ['_idoc' => array_filter(['DOCNUM' => $docnum, 'IDOCTYP' => trim((string) $control->IDOCTYP), 'MESTYP' => $type, 'SNDPRN' => trim((string) $control->SNDPRN)])]];
        }

        return ['envelopes' => $envelopes, 'rejected' => $rejected];
    }

    private function flatten(SimpleXMLElement $segment): array
    {
        $out = [];
        foreach ($segment->children() as $name => $child) {
            if ($child->count() > 0) {
                $out[$name][] = $this->flatten($child);
            } else {
                $out[$name] = trim((string) $child);
            }
        }

        return $out;
    }
}
