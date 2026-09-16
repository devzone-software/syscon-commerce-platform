package pe.syscon.catalog.domain;
import jakarta.persistence.*;
import java.util.UUID;
@Entity public class Category {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @Column(nullable=false,unique=true) public String name;
 protected Category() {} public Category(String name) {this.name=name;}
}
