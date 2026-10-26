package ec.condore.facsto.signer;

import java.io.ByteArrayOutputStream;
import java.io.FileInputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.PrivateKey;
import java.security.SecureRandom;
import java.security.cert.X509Certificate;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;
import java.time.temporal.ChronoUnit;
import java.util.Arrays;
import java.util.Base64;
import java.util.Collections;
import java.util.Enumeration;
import java.util.List;

import javax.security.auth.x500.X500Principal;
import javax.xml.XMLConstants;
import javax.xml.crypto.dom.DOMStructure;
import javax.xml.crypto.dsig.CanonicalizationMethod;
import javax.xml.crypto.dsig.DigestMethod;
import javax.xml.crypto.dsig.Reference;
import javax.xml.crypto.dsig.SignatureMethod;
import javax.xml.crypto.dsig.SignedInfo;
import javax.xml.crypto.dsig.Transform;
import javax.xml.crypto.dsig.XMLObject;
import javax.xml.crypto.dsig.XMLSignature;
import javax.xml.crypto.dsig.XMLSignatureFactory;
import javax.xml.crypto.dsig.dom.DOMSignContext;
import javax.xml.crypto.dsig.keyinfo.KeyInfo;
import javax.xml.crypto.dsig.keyinfo.KeyInfoFactory;
import javax.xml.crypto.dsig.spec.C14NMethodParameterSpec;
import javax.xml.crypto.dsig.spec.TransformParameterSpec;
import javax.xml.parsers.DocumentBuilderFactory;
import javax.xml.transform.OutputKeys;
import javax.xml.transform.Transformer;
import javax.xml.transform.TransformerFactory;
import javax.xml.transform.dom.DOMSource;
import javax.xml.transform.stream.StreamResult;

import org.w3c.dom.Document;
import org.w3c.dom.Element;

/**
 * Firma un comprobante del SRI con XAdES-BES (ficha técnica offline v2.34, tabla 7 y anexo 14).
 *
 * Uso: java -jar xades-signer.jar --p12 RUTA --input RUTA_XML
 * La contraseña del PKCS#12 se lee de la entrada estándar (nunca de argumentos ni archivos).
 * El XML firmado sale por la salida estándar; los errores van a stderr con un código estable.
 */
public final class XadesBesSigner {

    private static final String XADES_NS = "http://uri.etsi.org/01903/v1.3.2#";
    private static final String XMLNS_NS = "http://www.w3.org/2000/xmlns/";
    private static final String SIGNED_PROPERTIES_TYPE = "http://uri.etsi.org/01903#SignedProperties";
    private static final String COMPROBANTE_ID = "comprobante";

    static final int EXIT_USAGE = 2;
    static final int EXIT_KEYSTORE = 3;
    static final int EXIT_XML = 4;
    static final int EXIT_SIGNING = 5;

    private XadesBesSigner() {
    }

    public static void main(String[] args) {
        // Base64 values without line breaks: the SRI rejects "&#13;" inside SignatureValue/Certificate.
        System.setProperty("com.sun.org.apache.xml.internal.security.ignoreLineBreaks", "true");
        System.exit(run(args));
    }

    static int run(String[] args) {
        String p12 = option(args, "--p12");
        String input = option(args, "--input");
        if (p12 == null || input == null) {
            return fail(EXIT_USAGE, "USAGE", "Uso: --p12 RUTA --input RUTA_XML (contraseña por stdin)");
        }

        char[] password;
        try {
            password = readPassword(System.in);
        } catch (Exception exception) {
            return fail(EXIT_USAGE, "PASSWORD_NOT_PROVIDED", exception.getMessage());
        }

        SigningMaterial material;
        try {
            material = SigningMaterial.load(Path.of(p12), password);
        } catch (Exception exception) {
            return fail(EXIT_KEYSTORE, "KEYSTORE_UNREADABLE", exception.getMessage());
        } finally {
            Arrays.fill(password, '\0');
        }

        Document document;
        try {
            document = parse(Path.of(input));
        } catch (Exception exception) {
            return fail(EXIT_XML, "XML_UNREADABLE", exception.getMessage());
        }

        try {
            sign(document, material);
            System.out.write(serialize(document));
            System.out.flush();
            return 0;
        } catch (Exception exception) {
            return fail(EXIT_SIGNING, "SIGNING_FAILED", exception.getClass().getSimpleName() + ": " + exception.getMessage());
        }
    }

    static void sign(Document document, SigningMaterial material) throws Exception {
        Element root = document.getDocumentElement();
        if (!COMPROBANTE_ID.equals(root.getAttribute("id"))) {
            throw new IllegalArgumentException("El elemento raíz debe tener id=\"comprobante\".");
        }
        root.setIdAttribute("id", true);

        SecureRandom random = new SecureRandom();
        String signatureId = "Signature" + number(random);
        String signedInfoId = "Signature-SignedInfo" + number(random);
        String signedPropertiesId = signatureId + "-SignedProperties" + number(random);
        String signedPropertiesReferenceId = "SignedPropertiesID" + number(random);
        String certificateId = "Certificate" + number(random);
        String comprobanteReferenceId = "Reference-ID-" + number(random);
        String signatureValueId = "SignatureValue" + number(random);
        String objectId = signatureId + "-Object" + number(random);

        X509Certificate certificate = material.certificate();
        Element qualifyingProperties = qualifyingProperties(document, certificate, signatureId, signedPropertiesId, comprobanteReferenceId);
        Element signedProperties = (Element) qualifyingProperties.getFirstChild();

        XMLSignatureFactory factory = XMLSignatureFactory.getInstance("DOM");
        DigestMethod sha1 = factory.newDigestMethod(DigestMethod.SHA1, null);
        List<Reference> references = List.of(
            factory.newReference("#" + signedPropertiesId, sha1, null, SIGNED_PROPERTIES_TYPE, signedPropertiesReferenceId),
            factory.newReference("#" + certificateId, sha1),
            factory.newReference(
                "#" + COMPROBANTE_ID,
                sha1,
                Collections.singletonList(factory.newTransform(Transform.ENVELOPED, (TransformParameterSpec) null)),
                null,
                comprobanteReferenceId
            )
        );
        SignedInfo signedInfo = factory.newSignedInfo(
            factory.newCanonicalizationMethod(CanonicalizationMethod.INCLUSIVE, (C14NMethodParameterSpec) null),
            factory.newSignatureMethod(SignatureMethod.RSA_SHA1, null),
            references,
            signedInfoId
        );

        KeyInfoFactory keyInfoFactory = factory.getKeyInfoFactory();
        KeyInfo keyInfo = keyInfoFactory.newKeyInfo(
            List.of(keyInfoFactory.newX509Data(List.of(certificate)), keyInfoFactory.newKeyValue(certificate.getPublicKey())),
            certificateId
        );
        XMLObject object = factory.newXMLObject(List.of(new DOMStructure(qualifyingProperties)), objectId, null, null);
        XMLSignature signature = factory.newXMLSignature(signedInfo, keyInfo, List.of(object), signatureId, signatureValueId);

        DOMSignContext context = new DOMSignContext(material.privateKey(), root);
        context.setDefaultNamespacePrefix("ds");
        context.setIdAttributeNS(signedProperties, null, "Id");
        signature.sign(context);
    }

    private static Element qualifyingProperties(Document document, X509Certificate certificate, String signatureId,
                                                String signedPropertiesId, String comprobanteReferenceId) throws Exception {
        Element qualifying = document.createElementNS(XADES_NS, "etsi:QualifyingProperties");
        // Explicit declarations: canonicalization relies on namespace attributes present in the DOM.
        qualifying.setAttributeNS(XMLNS_NS, "xmlns:etsi", XADES_NS);
        qualifying.setAttributeNS(XMLNS_NS, "xmlns:ds", XMLSignature.XMLNS);
        qualifying.setAttribute("Target", "#" + signatureId);

        Element signedProperties = append(document, qualifying, XADES_NS, "etsi:SignedProperties");
        signedProperties.setAttribute("Id", signedPropertiesId);

        Element signatureProperties = append(document, signedProperties, XADES_NS, "etsi:SignedSignatureProperties");
        String signingTime = ZonedDateTime.now(ZoneId.of("America/Guayaquil")).truncatedTo(ChronoUnit.SECONDS)
            .format(DateTimeFormatter.ISO_OFFSET_DATE_TIME);
        append(document, signatureProperties, XADES_NS, "etsi:SigningTime").setTextContent(signingTime);

        Element cert = append(document, append(document, signatureProperties, XADES_NS, "etsi:SigningCertificate"), XADES_NS, "etsi:Cert");
        Element certDigest = append(document, cert, XADES_NS, "etsi:CertDigest");
        append(document, certDigest, XMLSignature.XMLNS, "ds:DigestMethod").setAttribute("Algorithm", DigestMethod.SHA1);
        append(document, certDigest, XMLSignature.XMLNS, "ds:DigestValue").setTextContent(
            Base64.getEncoder().encodeToString(MessageDigest.getInstance("SHA-1").digest(certificate.getEncoded()))
        );
        Element issuerSerial = append(document, cert, XADES_NS, "etsi:IssuerSerial");
        append(document, issuerSerial, XMLSignature.XMLNS, "ds:X509IssuerName").setTextContent(
            certificate.getIssuerX500Principal().getName(X500Principal.RFC2253)
        );
        append(document, issuerSerial, XMLSignature.XMLNS, "ds:X509SerialNumber").setTextContent(certificate.getSerialNumber().toString());

        Element dataObjectFormat = append(document, append(document, signedProperties, XADES_NS, "etsi:SignedDataObjectProperties"), XADES_NS, "etsi:DataObjectFormat");
        dataObjectFormat.setAttribute("ObjectReference", "#" + comprobanteReferenceId);
        append(document, dataObjectFormat, XADES_NS, "etsi:Description").setTextContent("contenido comprobante");
        append(document, dataObjectFormat, XADES_NS, "etsi:MimeType").setTextContent("text/xml");

        return qualifying;
    }

    private static Element append(Document document, Element parent, String namespace, String qualifiedName) {
        Element element = document.createElementNS(namespace, qualifiedName);
        parent.appendChild(element);
        return element;
    }

    private static Document parse(Path input) throws Exception {
        DocumentBuilderFactory factory = DocumentBuilderFactory.newInstance();
        factory.setNamespaceAware(true);
        factory.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING, true);
        factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        factory.setXIncludeAware(false);
        factory.setExpandEntityReferences(false);
        Document document = factory.newDocumentBuilder().parse(Files.newInputStream(input));
        document.setXmlStandalone(true);
        return document;
    }

    private static byte[] serialize(Document document) throws Exception {
        Transformer transformer = TransformerFactory.newInstance().newTransformer();
        transformer.setOutputProperty(OutputKeys.ENCODING, "UTF-8");
        transformer.setOutputProperty(OutputKeys.INDENT, "no");
        ByteArrayOutputStream output = new ByteArrayOutputStream();
        transformer.transform(new DOMSource(document), new StreamResult(output));
        return output.toByteArray();
    }

    private static char[] readPassword(InputStream input) throws Exception {
        String password = new String(input.readAllBytes(), StandardCharsets.UTF_8);
        if (password.endsWith("\n")) {
            password = password.substring(0, password.length() - 1);
        }
        if (password.endsWith("\r")) {
            password = password.substring(0, password.length() - 1);
        }
        return password.toCharArray();
    }

    private static String option(String[] args, String name) {
        for (int index = 0; index < args.length - 1; index++) {
            if (name.equals(args[index])) {
                return args[index + 1];
            }
        }
        return null;
    }

    private static String number(SecureRandom random) {
        return String.valueOf(100000 + random.nextInt(900000));
    }

    private static int fail(int code, String reason, String detail) {
        System.err.println(reason + ": " + (detail == null ? "" : detail));
        return code;
    }

    /** Private key and certificate of the signing entry of a PKCS#12 file. */
    record SigningMaterial(PrivateKey privateKey, X509Certificate certificate) {

        static SigningMaterial load(Path path, char[] password) throws Exception {
            KeyStore keyStore = KeyStore.getInstance("PKCS12");
            try (InputStream input = new FileInputStream(path.toFile())) {
                keyStore.load(input, password);
            }

            String fallback = null;
            Enumeration<String> aliases = keyStore.aliases();
            while (aliases.hasMoreElements()) {
                String alias = aliases.nextElement();
                if (!keyStore.isKeyEntry(alias) || !(keyStore.getCertificate(alias) instanceof X509Certificate certificate)) {
                    continue;
                }
                fallback = fallback == null ? alias : fallback;
                boolean[] usage = certificate.getKeyUsage();
                // Prefer the entry meant for signatures (digitalSignature or nonRepudiation).
                if (usage != null && usage.length > 1 && (usage[0] || usage[1])) {
                    return entry(keyStore, alias, password);
                }
            }
            if (fallback == null) {
                throw new IllegalStateException("El archivo no contiene una llave privada con certificado X.509.");
            }
            return entry(keyStore, fallback, password);
        }

        private static SigningMaterial entry(KeyStore keyStore, String alias, char[] password) throws Exception {
            return new SigningMaterial((PrivateKey) keyStore.getKey(alias, password), (X509Certificate) keyStore.getCertificate(alias));
        }
    }
}
